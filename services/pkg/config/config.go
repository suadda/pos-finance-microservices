// Package config reads service configuration from environment variables.
package config

import (
	"log"
	"os"
	"strconv"
	"time"
)

// Env returns the value of key or def when it is unset/empty.
func Env(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

// MustEnv returns the value of key and aborts the process when it is missing.
func MustEnv(key string) string {
	v := os.Getenv(key)
	if v == "" {
		log.Fatalf("config: environment variable %s is required", key)
	}
	return v
}

// Duration parses a Go duration (e.g. "3s", "15m") with a fallback.
func Duration(key string, def time.Duration) time.Duration {
	if v := os.Getenv(key); v != "" {
		if d, err := time.ParseDuration(v); err == nil {
			return d
		}
		log.Printf("config: invalid duration for %s=%q, using %s", key, v, def)
	}
	return def
}

// Int parses an integer with a fallback.
func Int(key string, def int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return def
}
