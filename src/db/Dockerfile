FROM mariadb:11.4.2

ENV TZ=Asia/Hong_Kong

COPY mariadb.cnf /etc/mysql/mariadb.conf.d/99-dept.cnf
COPY capstone_project.sql /docker-entrypoint-initdb.d/02-capstone_project.sql
COPY docker-entrypoint-initdb.d/01-charset.sh /docker-entrypoint-initdb.d/01-charset.sh

RUN chmod 755 /docker-entrypoint-initdb.d/01-charset.sh

EXPOSE 3306
