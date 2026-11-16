FROM mariadb:11.4.2

ENV TZ=Asia/Hong_Kong

COPY mariadb.cnf /etc/mysql/mariadb.conf.d/99-dept.cnf
COPY capstone_project.sql /docker-entrypoint-initdb.d/02-capstone_project.sql
COPY docker-entrypoint-initdb.d/01-charset.sh /docker-entrypoint-initdb.d/01-charset.sh

# A Windows checkout may save the script with CRLF. The shebang is then /bin/sh\r,
# and MariaDB reports "cannot execute: required file not found" and skips the schema.
RUN sed -i 's/\r$//' /docker-entrypoint-initdb.d/01-charset.sh \
    && chmod 755 /docker-entrypoint-initdb.d/01-charset.sh

EXPOSE 3306
