// Package httpx contains small, dependency-free HTTP helpers shared by all services.
package httpx

import (
	"errors"
	"fmt"
	"net/http"
)

// AppError is a business/HTTP error with a stable machine-readable code.
type AppError struct {
	Status  int            `json:"-"`
	Code    string         `json:"code"`
	Message string         `json:"message"`
	Details map[string]any `json:"details,omitempty"`
}

func (e *AppError) Error() string { return fmt.Sprintf("%d %s: %s", e.Status, e.Code, e.Message) }

// WithDetails returns a copy of e with extra details attached.
func (e *AppError) WithDetails(d map[string]any) *AppError {
	c := *e
	c.Details = d
	return &c
}

func newErr(status int, code, format string, args ...any) *AppError {
	return &AppError{Status: status, Code: code, Message: fmt.Sprintf(format, args...)}
}

func BadRequest(format string, a ...any) *AppError {
	return newErr(http.StatusBadRequest, "BAD_REQUEST", format, a...)
}
func Unauthorized(format string, a ...any) *AppError {
	return newErr(http.StatusUnauthorized, "UNAUTHORIZED", format, a...)
}
func Forbidden(format string, a ...any) *AppError {
	return newErr(http.StatusForbidden, "FORBIDDEN", format, a...)
}
func NotFound(format string, a ...any) *AppError {
	return newErr(http.StatusNotFound, "NOT_FOUND", format, a...)
}
func Conflict(code, format string, a ...any) *AppError {
	return newErr(http.StatusConflict, code, format, a...)
}
func Unprocessable(code, format string, a ...any) *AppError {
	return newErr(http.StatusUnprocessableEntity, code, format, a...)
}
func ServiceUnavailable(format string, a ...any) *AppError {
	return newErr(http.StatusServiceUnavailable, "SERVICE_UNAVAILABLE", format, a...)
}

// ValidationError maps field -> message and is returned as 422.
type ValidationError map[string]string

func (v ValidationError) Error() string { return "validation failed" }

// Add records a field error.
func (v ValidationError) Add(field, msg string) { v[field] = msg }

// OrNil returns nil when no field errors were recorded.
func (v ValidationError) OrNil() error {
	if len(v) == 0 {
		return nil
	}
	return v
}

// toAppError converts any error into an AppError; unknown errors become 500.
func toAppError(err error) *AppError {
	var ae *AppError
	if errors.As(err, &ae) {
		return ae
	}
	var ve ValidationError
	if errors.As(err, &ve) {
		fields := make(map[string]any, len(ve))
		for k, v := range ve {
			fields[k] = v
		}
		return &AppError{
			Status:  http.StatusUnprocessableEntity,
			Code:    "VALIDATION_ERROR",
			Message: "Input tidak valid",
			Details: map[string]any{"fields": fields},
		}
	}
	return &AppError{Status: http.StatusInternalServerError, Code: "INTERNAL_ERROR", Message: "Terjadi kesalahan pada server"}
}
