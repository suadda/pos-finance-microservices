package httpx

import (
	"encoding/json"
	"errors"
	"io"
	"log"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// HandlerFunc is an http handler that returns an error; Handle converts it to a JSON response.
type HandlerFunc func(w http.ResponseWriter, r *http.Request) error

// Handle adapts a HandlerFunc to http.HandlerFunc with uniform error rendering.
func Handle(fn HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if err := fn(w, r); err != nil {
			WriteError(w, r, err)
		}
	}
}

// JSON writes v with the given status.
func JSON(w http.ResponseWriter, status int, v any) error {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	return json.NewEncoder(w).Encode(v)
}

// OK writes {"data": v} with 200.
func OK(w http.ResponseWriter, v any) error { return JSON(w, http.StatusOK, map[string]any{"data": v}) }

// Created writes {"data": v} with 201.
func Created(w http.ResponseWriter, v any) error {
	return JSON(w, http.StatusCreated, map[string]any{"data": v})
}

// WriteError renders err as {"error": {...}}; 5xx are logged with the underlying cause.
func WriteError(w http.ResponseWriter, r *http.Request, err error) {
	ae := toAppError(err)
	if ae.Status >= 500 {
		log.Printf("ERROR %s %s: %v", r.Method, r.URL.Path, err)
	}
	_ = JSON(w, ae.Status, map[string]any{"error": ae})
}

// Decode parses a JSON body into dst, rejecting unknown fields and oversized bodies.
func Decode(r *http.Request, dst any) error {
	dec := json.NewDecoder(io.LimitReader(r.Body, 1<<20))
	dec.DisallowUnknownFields()
	if err := dec.Decode(dst); err != nil {
		if errors.Is(err, io.EOF) {
			return BadRequest("Body request kosong")
		}
		return BadRequest("Body JSON tidak valid: %v", err)
	}
	return nil
}

// PathID parses a positive integer path parameter.
func PathID(r *http.Request, name string) (uint64, error) {
	id, err := strconv.ParseUint(r.PathValue(name), 10, 64)
	if err != nil || id == 0 {
		return 0, BadRequest("Parameter %s tidak valid", name)
	}
	return id, nil
}

// QueryUint parses an optional unsigned integer query parameter (0 when absent).
func QueryUint(r *http.Request, name string) (uint64, error) {
	raw := strings.TrimSpace(r.URL.Query().Get(name))
	if raw == "" {
		return 0, nil
	}
	n, err := strconv.ParseUint(raw, 10, 64)
	if err != nil {
		return 0, BadRequest("Parameter %s harus berupa angka", name)
	}
	return n, nil
}

// Page describes pagination input.
type Page struct {
	Page    int
	PerPage int
}

// Offset returns the SQL OFFSET for the page.
func (p Page) Offset() int { return (p.Page - 1) * p.PerPage }

// ParsePage reads ?page & ?per_page (defaults 1 / 20, max 100).
func ParsePage(r *http.Request) Page {
	page, _ := strconv.Atoi(r.URL.Query().Get("page"))
	per, _ := strconv.Atoi(r.URL.Query().Get("per_page"))
	if page < 1 {
		page = 1
	}
	if per < 1 {
		per = 20
	}
	if per > 100 {
		per = 100
	}
	return Page{Page: page, PerPage: per}
}

// Paginated writes a list response with pagination meta.
func Paginated(w http.ResponseWriter, items any, p Page, total int) error {
	lastPage := (total + p.PerPage - 1) / p.PerPage
	if lastPage == 0 {
		lastPage = 1
	}
	return JSON(w, http.StatusOK, map[string]any{
		"data": items,
		"meta": map[string]any{"page": p.Page, "per_page": p.PerPage, "total": total, "last_page": lastPage},
	})
}

// Logging logs method, path, status and latency of every request.
func Logging(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		rec := &statusRecorder{ResponseWriter: w, status: http.StatusOK}
		next.ServeHTTP(rec, r)
		log.Printf("%s %s -> %d (%s)", r.Method, r.URL.RequestURI(), rec.status, time.Since(start).Round(time.Millisecond))
	})
}

// Recover converts panics into 500 responses.
func Recover(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if p := recover(); p != nil {
				log.Printf("PANIC %s %s: %v", r.Method, r.URL.Path, p)
				WriteError(w, r, errors.New("panic"))
			}
		}()
		next.ServeHTTP(w, r)
	})
}

type statusRecorder struct {
	http.ResponseWriter
	status int
}

func (s *statusRecorder) WriteHeader(code int) {
	s.status = code
	s.ResponseWriter.WriteHeader(code)
}

// Serve starts an HTTP server with sane timeouts.
func Serve(addr string, h http.Handler) error {
	srv := &http.Server{
		Addr:              addr,
		Handler:           Recover(Logging(h)),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      30 * time.Second,
	}
	log.Printf("listening on %s", addr)
	return srv.ListenAndServe()
}
