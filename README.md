
### Migrate between MySQL DBs using ProxySQL

Example usage with provided docker-compose M1, M2 and ProxySQL:
```
./run switchover \
  --m1 127.0.0.1:3362 --m1-user root \
  --m2 127.0.0.1:3361 --m2-user root \
  --proxysql 127.0.0.1:6032 --proxysql-user radmin \
  -c config.yml \
  --m1-proxysql m1:3306 \
  --m2-proxysql m2:3306
```

For full help please run `./run switchover --help`
