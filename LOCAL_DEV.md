# Local Development — local_vbs_myoverview

## Prerequisites

- PHP 8.1+
- A running Moodle 4.4 installation with this plugin installed

## Running lints locally

### PHP lint

```bash
find classes db -name "*.php" | xargs php -l
php -l version.php
```

## Running PHPUnit tests

PHPUnit tests require a full Moodle test environment. Follow the
[Moodle PHPUnit guide](https://moodledev.io/general/development/tools/phpunit).

Once your environment is configured:

```bash
# From the Moodle root
php admin/tool/phpunit/cli/init.php

# Run all plugin tests
vendor/bin/phpunit --testsuite local_vbs_myoverview_testsuite

# Run individual test classes
vendor/bin/phpunit --filter badge_mapper_test
vendor/bin/phpunit --filter state_computer_test
vendor/bin/phpunit --filter enrich_courses_test
```

## CI

CI runs automatically on push to `main` and on pull requests.
See `.github/workflows/ci.yml` for the full pipeline (PHP lint + PHPUnit on
Moodle 4.4 / PostgreSQL 14 / PHP 8.1).
