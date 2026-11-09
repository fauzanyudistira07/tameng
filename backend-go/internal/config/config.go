package config

import (
	"log"
	"os"
	"strconv"

	"github.com/joho/godotenv"
)

type Config struct {
	Port              string
	AppEnv            string
	AppDebug          bool
	AppURL            string
	DBHost            string
	DBPort            string
	DBDatabase        string
	DBName            string
	DBUsername        string
	DBUser            string
	DBPassword        string
	RedisHost         string
	RedisPort         string
	RedisPassword     string
	MailHost          string
	MailPort          int
	MailUsername      string
	MailPassword      string
	MailFromAddress   string
	MailFromName      string
	SessionCookieName string
	SessionSecret     string
	AIProvider        string
	GeminiAPIKey      string
	GeminiModel       string
	AITimeoutSeconds  int
}

func LoadConfig() *Config {
	// Try loading .env from current dir or project root
	if err := godotenv.Load(); err != nil {
		if err := godotenv.Load("/opt/tameng/tameng/backend-go/.env"); err != nil {
			log.Println("[CONFIG] Notice: .env file not found, using environment variables")
		}
	}

	mailPort, _ := strconv.Atoi(getEnv("MAIL_PORT", "587"))
	dbDatabase := getEnv("DB_DATABASE", "secsys")
	dbUsername := getEnv("DB_USERNAME", "secsys")
	aiTimeout, _ := strconv.Atoi(getEnv("AI_TIMEOUT_SECONDS", "30"))

	return &Config{
		Port:              getEnv("PORT", "8001"),
		AppEnv:            getEnv("APP_ENV", "local"),
		AppDebug:          getEnv("APP_DEBUG", "true") == "true",
		AppURL:            getEnv("APP_URL", "http://localhost:8001"),
		DBHost:            getEnv("DB_HOST", "127.0.0.1"),
		DBPort:            getEnv("DB_PORT", "3307"),
		DBDatabase:        dbDatabase,
		DBName:            dbDatabase,
		DBUsername:        dbUsername,
		DBUser:            dbUsername,
		DBPassword:        getEnv("DB_PASSWORD", "secsys_secret"),
		RedisHost:         getEnv("REDIS_HOST", "127.0.0.1"),
		RedisPort:         getEnv("REDIS_PORT", "6380"),
		RedisPassword:     getEnv("REDIS_PASSWORD", ""),
		MailHost:          getEnv("MAIL_HOST", "smtp.gmail.com"),
		MailPort:          mailPort,
		MailUsername:      getEnv("MAIL_USERNAME", ""),
		MailPassword:      getEnv("MAIL_PASSWORD", ""),
		MailFromAddress:   getEnv("MAIL_FROM_ADDRESS", "noreply@tameng.local"),
		MailFromName:      getEnv("MAIL_FROM_NAME", "TAMENG SOC"),
		SessionCookieName: getEnv("SESSION_COOKIE_NAME", "secsys_session"),
		SessionSecret:     getEnv("SESSION_SECRET", "tameng-secret-key"),
		AIProvider:        getEnv("AI_PROVIDER", "gemini"),
		GeminiAPIKey:      getEnv("GEMINI_API_KEY", ""),
		GeminiModel:       getEnv("GEMINI_MODEL", "gemini-3.5-flash-lite"),
		AITimeoutSeconds:  aiTimeout,
	}
}

func getEnv(key, fallback string) string {
	if val, ok := os.LookupEnv(key); ok && val != "" {
		return val
	}
	return fallback
}
