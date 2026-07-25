# AI Project TechTrack - Backend

## Getting Started

### Prerequisites
- Docker and Docker Compose
- Git
- PHP 8.1+
- Composer

### Installation

1. Clone the repository:
   ```bash
   git clone [your-repository-url]
   cd AIProjectTechTrack/backend
   ```

2. Copy the example env file and make the required configuration changes:
   ```bash
   cp .env.example .env
   ```

3. Install PHP dependencies:
   ```bash
   composer install
   ```

4. Generate application key:
   ```bash
   php artisan key:generate
   ```

## Docker Setup

### Start the application
```bash
docker compose up -d
```

### Stop the application
```bash
docker compose down
```

### Rebuild containers
```bash
docker compose up -d --build
```

### Access container bash
```bash
docker compose exec app bash
# or
docker exec -it backend_app bash
```

## Database Configuration

```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=backend
DB_USERNAME=backend
DB_PASSWORD=secret
```

## Access URLs

- **Backend URL:** http://localhost:8085
- **Database Admin (phpMyAdmin):** http://localhost:8086/index.php


## Local Development (Without Docker)

### Start local server
```bash
php artisan serve
```

### Run migrations
```bash
php artisan migrate
```

### Run database seeders
```bash
php artisan db:seed
```

## Module Management

Enable or disable modules by editing the `module.json` file in each module's directory.


## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
