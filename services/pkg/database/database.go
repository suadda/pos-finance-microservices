// Package database opens MySQL connections and provides transaction helpers.
package database

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"log"
	"time"

	"github.com/go-sql-driver/mysql"

	"posfinance/pkg/config"
	"posfinance/pkg/timex"
)

// OpenFromEnv connects using DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME
// and waits (with retries) until the database accepts connections.
func OpenFromEnv() (*sql.DB, error) {
	cfg := mysql.NewConfig()
	cfg.Net = "tcp"
	cfg.Addr = fmt.Sprintf("%s:%s", config.Env("DB_HOST", "127.0.0.1"), config.Env("DB_PORT", "3306"))
	cfg.User = config.Env("DB_USER", "root")
	cfg.Passwd = config.Env("DB_PASSWORD", "")
	cfg.DBName = config.MustEnv("DB_NAME")
	cfg.ParseTime = true
	cfg.Loc = timex.Jakarta
	cfg.Params = map[string]string{"time_zone": "'+07:00'"}

	db, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(25)
	db.SetMaxIdleConns(10)
	db.SetConnMaxLifetime(5 * time.Minute)

	const attempts = 30
	for i := 1; i <= attempts; i++ {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		err = db.PingContext(ctx)
		cancel()
		if err == nil {
			return db, nil
		}
		log.Printf("database: waiting for %s (%d/%d): %v", cfg.Addr, i, attempts, err)
		time.Sleep(2 * time.Second)
	}
	return nil, fmt.Errorf("database: unreachable after %d attempts: %w", attempts, err)
}

// Querier is satisfied by both *sql.DB and *sql.Tx so repositories work in and out of transactions.
type Querier interface {
	ExecContext(ctx context.Context, query string, args ...any) (sql.Result, error)
	QueryContext(ctx context.Context, query string, args ...any) (*sql.Rows, error)
	QueryRowContext(ctx context.Context, query string, args ...any) *sql.Row
}

// WithTx runs fn in a transaction, committing on success and rolling back on error/panic.
func WithTx(ctx context.Context, db *sql.DB, fn func(tx *sql.Tx) error) (err error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() {
		if p := recover(); p != nil {
			_ = tx.Rollback()
			panic(p)
		}
		if err != nil {
			_ = tx.Rollback()
		}
	}()
	if err = fn(tx); err != nil {
		return err
	}
	return tx.Commit()
}

// IsDuplicateKey reports whether err is a MySQL unique-constraint violation (1062).
func IsDuplicateKey(err error) bool {
	var me *mysql.MySQLError
	return errors.As(err, &me) && me.Number == 1062
}

// IsNotFound reports whether err is sql.ErrNoRows.
func IsNotFound(err error) bool { return errors.Is(err, sql.ErrNoRows) }
