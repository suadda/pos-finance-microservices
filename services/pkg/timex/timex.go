// Package timex centralises the Asia/Jakarta business timezone and the DATE type.
package timex

import (
	"database/sql/driver"
	"encoding/json"
	"fmt"
	"time"
	_ "time/tzdata" // embed tz database so Alpine/scratch images work
)

const DateLayout = "2006-01-02"

// Jakarta is the single timezone used to derive business_date.
var Jakarta = mustLoad("Asia/Jakarta")

func mustLoad(name string) *time.Location {
	loc, err := time.LoadLocation(name)
	if err != nil {
		panic(err)
	}
	return loc
}

// Now returns the current time in Asia/Jakarta.
func Now() time.Time { return time.Now().In(Jakarta) }

// Today returns today's business date in Asia/Jakarta.
func Today() Date { return DateOf(Now()) }

// Date is a calendar date (no time component) serialised as "YYYY-MM-DD".
type Date struct{ time.Time }

// DateOf truncates t to its calendar date in Asia/Jakarta.
func DateOf(t time.Time) Date {
	y, m, d := t.In(Jakarta).Date()
	return Date{time.Date(y, m, d, 0, 0, 0, 0, Jakarta)}
}

// ParseDate parses "YYYY-MM-DD".
func ParseDate(s string) (Date, error) {
	t, err := time.ParseInLocation(DateLayout, s, Jakarta)
	if err != nil {
		return Date{}, fmt.Errorf("invalid date %q, expected YYYY-MM-DD", s)
	}
	return Date{t}, nil
}

func (d Date) String() string { return d.Format(DateLayout) }

// Compact returns the date as YYYYMMDD (used in transaction numbers).
func (d Date) Compact() string { return d.Format("20060102") }

func (d Date) MarshalJSON() ([]byte, error) { return json.Marshal(d.String()) }

func (d *Date) UnmarshalJSON(b []byte) error {
	var s string
	if err := json.Unmarshal(b, &s); err != nil {
		return err
	}
	parsed, err := ParseDate(s)
	if err != nil {
		return err
	}
	*d = parsed
	return nil
}

// Value stores the date as a plain string so MySQL never shifts it by timezone.
func (d Date) Value() (driver.Value, error) { return d.String(), nil }

func (d *Date) Scan(src any) error {
	switch v := src.(type) {
	case time.Time:
		*d = Date{time.Date(v.Year(), v.Month(), v.Day(), 0, 0, 0, 0, Jakarta)}
		return nil
	case []byte:
		return d.scanString(string(v))
	case string:
		return d.scanString(v)
	}
	return fmt.Errorf("timex.Date: cannot scan %T", src)
}

func (d *Date) scanString(s string) error {
	parsed, err := ParseDate(s[:min(len(s), 10)])
	if err != nil {
		return err
	}
	*d = parsed
	return nil
}
