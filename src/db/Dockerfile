FROM mariadb:11.4.2

ENV TZ=Asia/Hong_Kong \
    MARIADB_ROOT_PASSWORD=root_local_only \
    MARIADB_DATABASE=25000000d \
    MARIADB_USER=User1 \
    MARIADB_PASSWORD=Password123

COPY mariadb.cnf /etc/mysql/mariadb.conf.d/99-dept.cnf

EXPOSE 3306
