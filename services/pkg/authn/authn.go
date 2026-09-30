// Package authn provides request authentication for downstream services:
// JWT validation via the Auth Service (GET /auth/me), role guards and the
// X-Service-Key guard for /internal/* endpoints.
package authn

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"fmt"
	"net/http"
	"slices"
	"strings"
	"time"

	"posfinance/pkg/httpx"
)

// Role names shared by all services.
const (
	RoleSuperadmin     = "superadmin"
	RoleKasir          = "kasir"
	RoleSupervisorPOS  = "supervisor_pos"
	RoleStaffFinance   = "staff_finance"
	RoleManagerFinance = "manager_finance"
)

// AllRoles lists every supported role.
var AllRoles = []string{RoleSuperadmin, RoleKasir, RoleSupervisorPOS, RoleStaffFinance, RoleManagerFinance}

// ServiceKeyHeader is the header used for service-to-service calls.
const ServiceKeyHeader = "X-Service-Key"

// User is the identity returned by GET /auth/me.
type User struct {
	ID       uint64  `json:"id"`
	Name     string  `json:"name"`
	Email    string  `json:"email"`
	Role     string  `json:"role"`
	OutletID *uint64 `json:"outlet_id"`
	IsActive bool    `json:"is_active"`
}

// Is reports whether the user has any of the given roles.
func (u User) Is(roles ...string) bool { return slices.Contains(roles, u.Role) }

type ctxKey struct{}

// FromContext returns the authenticated user stored by Middleware.
func FromContext(ctx context.Context) User {
	u, _ := ctx.Value(ctxKey{}).(User)
	return u
}

// WithUser stores u in ctx (useful for tests).
func WithUser(ctx context.Context, u User) context.Context {
	return context.WithValue(ctx, ctxKey{}, u)
}

// Client validates bearer tokens against the Auth Service.
type Client struct {
	baseURL string
	http    *http.Client
}

// NewClient creates an Auth Service client with the given timeout.
func NewClient(baseURL string, timeout time.Duration) *Client {
	return &Client{baseURL: strings.TrimRight(baseURL, "/"), http: &http.Client{Timeout: timeout}}
}

// Me resolves the token to a user. It returns an *httpx.AppError on failure.
func (c *Client) Me(ctx context.Context, bearer string) (User, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/auth/me", nil)
	if err != nil {
		return User{}, err
	}
	req.Header.Set("Authorization", bearer)

	resp, err := c.http.Do(req)
	if err != nil {
		return User{}, httpx.ServiceUnavailable("Auth Service tidak dapat dihubungi")
	}
	defer resp.Body.Close()

	switch {
	case resp.StatusCode == http.StatusOK:
	case resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden:
		return User{}, httpx.Unauthorized("Token tidak valid atau akun nonaktif")
	default:
		return User{}, httpx.ServiceUnavailable("Auth Service mengembalikan status %d", resp.StatusCode)
	}

	var body struct {
		Data User `json:"data"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		return User{}, fmt.Errorf("authn: decode /auth/me: %w", err)
	}
	if !body.Data.IsActive {
		return User{}, httpx.Unauthorized("Akun nonaktif")
	}
	return body.Data, nil
}

// Middleware authenticates every request with the Auth Service.
func (c *Client) Middleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		bearer := r.Header.Get("Authorization")
		if !strings.HasPrefix(bearer, "Bearer ") {
			httpx.WriteError(w, r, httpx.Unauthorized("Header Authorization Bearer wajib diisi"))
			return
		}
		user, err := c.Me(r.Context(), bearer)
		if err != nil {
			httpx.WriteError(w, r, err)
			return
		}
		next.ServeHTTP(w, r.WithContext(WithUser(r.Context(), user)))
	})
}

// Require wraps a handler so only the given roles may call it.
func Require(roles ...string) func(http.HandlerFunc) http.HandlerFunc {
	return func(next http.HandlerFunc) http.HandlerFunc {
		return func(w http.ResponseWriter, r *http.Request) {
			if !FromContext(r.Context()).Is(roles...) {
				httpx.WriteError(w, r, httpx.Forbidden("Role Anda tidak memiliki akses ke resource ini"))
				return
			}
			next(w, r)
		}
	}
}

// ServiceKey guards /internal/* endpoints with a constant-time key comparison.
func ServiceKey(expected string) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			got := r.Header.Get(ServiceKeyHeader)
			if expected == "" || subtle.ConstantTimeCompare([]byte(got), []byte(expected)) != 1 {
				httpx.WriteError(w, r, httpx.Forbidden("Service key tidak valid"))
				return
			}
			next.ServeHTTP(w, r)
		})
	}
}
