# E-Banking API

A lightweight, production-oriented REST backend for personal e-banking. Users can manage multi-currency accounts, view and search transactions, and transfer funds (including FX). Admins get elevated endpoints for oversight. The API ships with OpenAPI (Swagger) docs and consistent JSON resources.

## Features

Auth & RBAC: Laravel Sanctum bearer tokens; roles: admin / user

Accounts: RSD (default) + EUR, USD, CHF, JPY; balances stored in minor units (no float issues)

Transactions: transfer, credit, debit; same-currency and cross-currency (with recorded FX rate)

FX Rates:

Real-time pair rates via Exchangerate-API v6 (supports RSD)

Public “today” RSD rates via NBS Kurs (no API key)

Categories: tag transactions and search by category/name

Filtering & Search: by type, currency, date range, amount range, description; sorting + pagination

Admin endpoints: fetch a user’s accounts & all related transactions

Swagger UI: interactive docs at /api/documentation

## Tech stack

Laravel, PHP, MySQL

Sanctum for tokens

L5-Swagger for OpenAPI docs

HTTP Client for external FX APIs

## Getting started

1. Prerequisites

PHP 8.2+ with extensions: pdo_mysql, mbstring, openssl, curl, json, xml

Composer

MySQL/MariaDB

Node (optional, only if you plan to serve a UI)

2. Clone & install
   git clone <your-repo-url>
   cd e-banking
   composer install
   cp .env.example .env
   php artisan key:generate

3. Configure environment

Edit .env:

APP_NAME="E-Banking API"
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=e_banking
DB_USERNAME=root
DB_PASSWORD=secret

Sanctum

SESSION_DRIVER=cookie
SANCTUM_STATEFUL_DOMAINS=localhost

Exchangerate-API v6 (pair endpoint)

EXR_V6_KEY=your_exchangerateapi_key_here

You can run without EXR_V6_KEY for same-currency transfers; FX transfers require a valid key.

4. Database: migrate & seed
   php artisan migrate
   php artisan db:seed

Seeds will create:

one admin user and a few users

1–2 accounts per user

common categories (e.g., Groceries, Utilities, Restaurants…)

several transactions (including FX examples)

5. Swagger (OpenAPI) setup

Install once (already done if committed):

composer require darkaonline/l5-swagger
php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider"

Generate docs (do this whenever you change annotations):

php artisan l5-swagger:generate

Open: http://localhost:8000/api/documentation

6. Run the server
   php artisan serve
