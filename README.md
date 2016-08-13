# pg_schema_dataclip
Minimal dataclip-like based on a postgres schema: each view in the schema is published as a web page table.

To test-run:

```bash
php -S localhost:8080 -t public/
```

The dataclip.php program takes parameters from environment variables, to 
make it easy to run under such things as Docker. 
Example `PG_SCHEMA_DATACLIP_CONNECTION_STRING` connection string (run this in 
the shell before starting dataclip):
 

```bash
 export PG_SCHEMA_DATACLIP_CONNECTION_STRING="user='dataclip_user' host='pghost' dbname='pgdatabase' password='pgpassword' sslmode='require'
```

If you would like to enable in-database logging, set the PG_SCHEMA_DATACLIP_ACCESS_LOG to any value.
 
```bash
$ export PG_SCHEMA_DATACLIP_ACCESS_LOG="on"
```

And create an access-log table with INSERT access to your `dataclip_user`; 


```sql
 SET search_path = dataclip_schema;
 CREATE TABLE dataclip_schema."##PG_SCHEMA_DATACLIP_ACCESS_LOG##" (
     viewname text,
     request_time timestamptz default now(),
     access_allowed boolean
 );
 GRANT INSERT on dataclip_schema."##PG_SCHEMA_DATACLIP_ACCESS_LOG##" to dataclip_user;
```


The database user (referred to as dataclip_user, but it could be any 
postgres user) should have as few privileges as possible.

You'll want to use a low setting for its connection limit, so it is less 
likely to create a denial of service against your database if it gets 
spammed with requests. Running through a proxy like pgbouncer might also be 
a good idea.

 
```sql
ALTER USER dataclip_user CONNECTION LIMIT 2;
```

USAGE on a single schema (referred to as dataclip_schema, but it could be 
any Postgres schema)
 
```sql
GRANT USAGE ON SCHEMA dataclip_schema TO dataclip_user;
```


SELECT permissions for tables and views in the dataclip_schema only.
 There are at least three options for how to manage this.

1. When new views are created, (option A) grant access normally.

```sql
    CREATE view dataclip_schema.foo AS select 'bar' AS bar;
    GRANT SELECT ON dataclip_schema.foo TO dataclip_user;
```

1. Or create views, then grant access to everything in the schema.  
You'll have to re-run the "GRANT SELECT ON ALL TABLES IN SCHEMA ..." command 
each time new views are created.

```sql
    CREATE view dataclip_schema.foo AS select 'bar'  AS bar;
    CREATE view dataclip_schema.baz AS select 'quux' AS quux;
    GRANT SELECT ON ALL TABLES IN SCHEMA dataclip_schema TO dataclip_user;
```

1. Or, use the handy postgres "ALTER DEFAULT PRIVILEGES" command to 
grant access in the future whenever views are created.
  a. `privileged_user` is the user you'll typically be using to CREATE the 
views.
  b. `dataclip_user` is the user used to SELECT from the views and show them on the web.


```sql
    ALTER DEFAULT PRIVILEGES 
        FOR ROLE privileged_user
        IN SCHEMA dataclip_schema 
        GRANT SELECT ON TABLES TO dataclip_user;
```


Views listed in ##PG_SCHEMA_DATACLIP_ACCESS_COOKIES## have mild security in 
the form of an access_cookie.  Multiple access_cookie may be listed for a view, in 
which case any of the access_cookie will allow access.

If there are no access_cookie listed for a view, then the view is default public.

To manage the access cookies:

```sql
    SET search_path = dataclip_schema;

    CREATE TABLE "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" (
        viewname      text not null, 
        access_cookie text not null default 'public',
        PRIMARY KEY (viewname, access_cookie)
    );

    GRANT SELECT ON "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" to dataclip_user;

    INSERT INTO 
        "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" 
        (viewname) values ('foo');

    INSERT INTO 
        "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" 
        (viewname, access_cookie) values ('bar', gen_random_uuid());

```



