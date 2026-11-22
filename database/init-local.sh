#!/bin/sh
set -eu

run_mysql() {
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket --user=root "$@"
}

for task in z1 z2 z3 z4 z5 z6a z6b; do
    run_mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$task\` CHARACTER SET utf8mb4 COLLATE utf8mb4_polish_ci;
CREATE USER IF NOT EXISTS '$task'@'%' IDENTIFIED BY 'local-only-$task';
GRANT ALL PRIVILEGES ON \`$task\`.* TO '$task'@'%';
SQL
    run_mysql "$task" < /database/schema.sql

    case "$task" in
        z1|z2|z3|z4|z5)
            run_mysql "$task" < /database/seed-local.sql
            ;;
    esac

    case "$task" in
        z4|z6a|z6b)
            run_mysql "$task" < "/database/$task.sql"
            ;;
    esac
done
