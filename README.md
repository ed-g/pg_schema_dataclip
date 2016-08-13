# pg_schema_dataclip
Minimal dataclip-like based on a postgres schema: each view in the schema is published as a web page table.

## To test-run:

```bash
php -S localhost:8080 -t public/
```

## Configuration

The dataclip.php program takes parameters from environment variables, to 
make it easy to run under such things as Docker. 


### Database connection

`PG_SCHEMA_DATACLIP_CONNECTION_STRING` connection string (run this in 
the shell before starting dataclip):


```bash
 export PG_SCHEMA_DATACLIP_CONNECTION_STRING="user='dataclip_user' host='pghost' dbname='pgdatabase' password='pgpassword' sslmode='require'
```

### In-database request logging

If you would like to enable in-database logging, set the PG_SCHEMA_DATACLIP_ACCESS_LOG to any value.
 
```bash
$ export PG_SCHEMA_DATACLIP_ACCESS_LOG="on"
```

And create an access-log table with INSERT access to your `dataclip_user`.

```sql
 SET search_path = dataclip_schema;
 CREATE TABLE dataclip_schema."##PG_SCHEMA_DATACLIP_ACCESS_LOG##" (
     viewname text,
     request_time timestamptz default now(),
     access_allowed boolean
 );
 GRANT INSERT on dataclip_schema."##PG_SCHEMA_DATACLIP_ACCESS_LOG##" to dataclip_user;
```

#### Aside on configuration table names

Note that all configuration table include uppercase letters and `#` in their names, in order to help prevent them from accidentally being exposed to the web.

Dataclip views may only use lowercase letters `[a-z]`, digits `[0-9]`, and underscores (`_`) in their names.

Postgres will typically require double quotes to refer to configuration table names.



## Database User 

The database user (referred to as `dataclip_user`, but it could be any 
postgres user) should have as few privileges as possible.

You'll want to use a low setting for its connection limit, so it is less 
likely to create a denial of service against your database if it gets 
spammed with requests. Running through a proxy like pgbouncer might also be 
a good idea.

 
```sql
ALTER USER dataclip_user CONNECTION LIMIT 2;
```

USAGE on a single schema (referred to as `dataclip_schema`, but it could be 
any Postgres schema)
 
```sql
GRANT USAGE ON SCHEMA dataclip_schema TO dataclip_user;
```

## Granting access to dataclip views

`dataclip_user` should have SELECT permissions for tables and views in the
`dataclip_schema` only.  There are at least three options for how to manage this.

### Whenever new views are created, grant access normally.

```sql
    CREATE view dataclip_schema.foo AS select 'bar' AS bar;
    GRANT SELECT ON dataclip_schema.foo TO dataclip_user;
```
### Create views, then grant access to everything in the schema.  

You'll have to re-run the "GRANT SELECT ON ALL TABLES IN SCHEMA ..." command 
each time new views are created.

```sql
    CREATE view dataclip_schema.foo AS select 'bar'  AS bar;
    CREATE view dataclip_schema.baz AS select 'quux' AS quux;
    GRANT SELECT ON ALL TABLES IN SCHEMA dataclip_schema TO dataclip_user;
```

### Use the handy postgres "ALTER DEFAULT PRIVILEGES" command

ALTER DEFAULT PRIVILEGES will grant access in the future whenever views are created.

`privileged_user` is the user you'll typically be using to CREATE the 
views.

`dataclip_user` is the user used to SELECT from the views and show them on the web.

```sql
    ALTER DEFAULT PRIVILEGES 
        FOR ROLE privileged_user
        IN SCHEMA dataclip_schema 
        GRANT SELECT ON TABLES TO dataclip_user;
```

## Access Cookies

Views listed in `##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##` have mild security in 
the form of an `access_cookie`.  Multiple `access_cookie`s may be listed for a view, in 
which case any of the `access_cookie`s will allow access.

If either there are no `access_cookie`s listed for a view, then the view is default public. 

Access cookies may use lowercase letters `[a-z]`, digits `[0-9]`, dash `-`, or underscore `_`.

Note: the `access_cookie` `'public'` is special and allows public access to a view.

I find UUIDs to be convenient for `access_cookie`s, but any string could be used.

### Creating a table to store `access_cookie`s

```sql

    /* Or you could use the "uuid-ossp" EXTENSION, in which case you'd want
       to use uuid_generate_v4() below, instead of gen_random_uuid() */
    CREATE EXTENSION pgcrypto; 
    
    SET search_path = dataclip_schema;

    CREATE TABLE "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" (
        viewname      text not null, 
        access_cookie text not null default gen_random_uuid(),
        PRIMARY KEY (viewname, access_cookie)
    );

    GRANT SELECT ON "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" to dataclip_user;
 ```
 
### Example `access_cookie` entries
 
 
#### Public entries
 
Views are public by default, so inserting entries with a 'public' access-cookie is redundant, but accepted.


       
 ```sql
    INSERT INTO 
        "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" 
        (viewname, access_cookie) values ('foo', 'public');
```
#### Private entries

##### Using default value of gen_random_uuid()

```sql
    INSERT INTO 
        "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" 
        (viewname) values ('bar')
    RETURNING viewname, access_cookie;
```
 
##### Using an UUID-format string.
    
```sql
    INSERT INTO 
        "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" 
        (viewname, access_cookie) values ('baz', '64ee7483-ae4c-4138-8a94-6fb09adefe3b');
```

##### Build an access-url using the return value from `INSERT`

```sql
WITH
new_cookie AS (
    INSERT INTO
        "##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##" (viewname )
        VALUES ('foo')
        RETURNING viewname, access_cookie
)
SELECT format('viewname?%s&access_cookie=%s', viewname, access_cookie) FROM new_cookie

/* Gives: 'viewname?foo&access_cookie=0a93f68f-7e01-47d6-bb26-9bd855eaba92' */
```


