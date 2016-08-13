<?php
#
# Copyright (c) 2016 Trillium Solutions http://trilliumtransit.com/
#
#   This program is free software: you can redistribute it and/or modify
#   it under the terms of the GNU General Public License as published by
#   the Free Software Foundation, either version 3 of the License, or
#   (at your option) any later version.
#
#   This program is distributed in the hope that it will be useful,
#   but WITHOUT ANY WARRANTY; without even the implied warranty of
#   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#   GNU General Public License for more details.
#
#   You should have received a copy of the GNU General Public License
#   along with this program.  If not, see <http://www.gnu.org/licenses/>.
#    


global $DB_CONNECTION;

function set_request_variables_from_command_line() {
    global $argv, $argc;
    // Expand command-line parameters
    // thanks to http://php.net/manual/en/function.array-slice.php comment by hamboy75
    if (null === $argv) {
        return;
    }
    foreach (array_slice($argv,1) as $arg) {
        $e=explode("=",$arg);
        if(count($e)==2) {
            $_GET[$e[0]]=$e[1];
            $_REQUEST[$e[0]]=$e[1];
        } else  {  
            $_GET[$e[0]]=0;
            $_REQUEST[$e[0]]=$e[1];
        }
    }
}

function db_query($query) {
    global $DB_CONNECTION;
    $q = pg_query($DB_CONNECTION, $query);
    if ($q === false) {
        error_log("query $query failed: ",  pg_last_error($DB_CONNECTION));
    }
    // echo("pg_result_error: " . pg_result_error($r));
    return $q;
}

function db_query_params($query, $params) {
    global $DB_CONNECTION;
    return pg_query_params($DB_CONNECTION, $query, $params);
}

function connect_to_database_or_die () {

    if (null !== getenv('PG_SCHEMA_DATACLIP_CONNECTION_STRING')) {
        global $DB_CONNECTION;
        $pg_connection_string = getenv('PG_SCHEMA_DATACLIP_CONNECTION_STRING');
        $DB_CONNECTION = pg_connect($pg_connection_string);

        //   print_r($DB_CONNECTION);
        $r = db_query("SELECT 1+1 as two;");

        $a = pg_fetch_array($r);
        if (intval($a["two"]) !== 2) {
            echo("pg_fetch_array: "); print_r($a);
            error_log("test query select 1+1 did not work, database connection is not working");
            exit(1);
        }
    } else {
        error_log("PG_SCHEMA_DATACLIP_CONNECTION_STRING environment variable not defined, please set one.");
        error_log("");
        error_log("Example connstring (run this in the shell, or via Apache config, etc. before starting dataclip):");
        error_log("\$ export PG_SCHEMA_DATACLIP_CONNECTION_STRING=\"user='pguser' host='pghost' dbname='pgdatabase' password='pgpassword' sslmode='require'\"");
        error_log("");
        error_log("PHP uses libpq, so passwords may also be stored in ~/.pgpass");
        error_log("More docs at http://php.net/manual/en/function.pg-connect.php");
        error_log("");
        exit(1);
    }
}

function pg_schema_dataclip_access_log_enabled(){
    # is the in-database access log configured?
    if (null !== getenv('PG_SCHEMA_DATACLIP_ACCESS_LOG')) {
        return True;
    } else {
        return False;
    }
}

function valid_viewname ($viewname) {
    ## Double check to disallow view for anything resembling an access control table.
    
    $invalid_view_regex = "/PG_SCHEMA_DATACLIP_ACCESS/i"; 

    $valid_view_regex = "/^[a-z0-9_]+$/";

    if (preg_match($invalid_view_regex, $viewname)) {
        return False;
    } elseif (preg_match($valid_view_regex, $viewname)) {
        return True;
    } else {
        error_log("viewname: [$viewname] does not match validation $valid_view_regex");
        return False;
    }
}


function viewname () {
    if (isset($_REQUEST['viewname'])) {
        $viewname = $_REQUEST['viewname'];
    }
    if (valid_viewname($viewname)) {
        return $viewname;
    } else {
        error_log("viewname: [$viewname] does not match validation $valid_view_regex");
        return null;
    }
}

function limit() {
    $default_limit = 5000;
    if (isset($_REQUEST['limit'])) {
        $limit_text = $_REQUEST['limit'];
        $limit      = intval($limit_text);
        if ($limit >= 0) {
            return $limit;
        } else {
            error_log("limit: [$limit_text] must be an integer greater than or equal to zero.");
        }
    }
    error_log("A valid limit was not supplied, defaulting to $default_limit");
    return $default_limit;
}

function offset() {
    $default_offset = 0;
    if (isset($_REQUEST['offset'])) {
        $offset_text = $_REQUEST['offset'];
        $offset      = intval($offset_text);
        if ($offset >= 0) {
            return $offset;
        } else {
            error_log("offset: [$offset_text] must be an integer greater than or equal to zero.");
        }
    } 
    error_log("A valid offset was not supplied, defaulting to $default_offset");
    return $default_offset;
}


function access_cookie () {
    // access_cookie is either a UUID for "private" dataclip views, or the 
    // string 'public' for "public" dataclip views.
    // 
    // The idea is a you can send someone a link embedding an access_cookie, 
    // for mild security.

    $valid_access_cookie_regex = "/^([0-9a-z_\-]+)$/"; # match uuid, or "public"
    if (isset($_REQUEST['access_cookie'])) {
        // force access_cookie to lower case.
        $access_cookie = strtolower($_REQUEST['access_cookie']);
    } else {
        error_log("access_cookie: not defined, returning public");
        // return null;
        return 'public';
    }
    if (preg_match($valid_access_cookie_regex, $access_cookie)) {
        return $access_cookie;
    } else {
        error_log("access_cookie: [$access_cookie] does not match validation $valid_access_cookie_regex");
        return 'public';
    }
}

function view_exists($viewname) {
    // In Postgres, current_schema() gives the first schema listed in "search_path".
    $r = db_query_params("
        SELECT count(*) AS view_count
        FROM pg_views
        WHERE viewname = $1
        AND schemaname = current_schema()
        ", [$viewname] );

    if (intval(pg_fetch_array($r)['view_count']) == 1) {
        error_log("View $viewname exists in the current_schema()");
        return  True;  # view found
    } else {
        error_log("View $viewname does not exist in the current_schema()");
        return  False; # no view found.
    }
    $no_restriction = (intval(pg_fetch_array($r_entries)['entry_count']) == 0) ? True : False;
}

function access_allowed($viewname, $access_cookie) {
    // Access control.
    //
    // Obviously if someone can dump the contents of 
    // ##PG_SCHEMA_DATACLIP_ACCESS_COOKIES## then they can access any data 
    // clip.
    //
    // Therefore, the access control table should given a name that can never 
    // be a valid view.
    //

    $r_entries = db_query_params("
        SELECT count(*) AS entry_count 
        FROM \"##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##\"
        WHERE viewname = $1
        ", [$viewname] );

    # Views default to PUBLIC access:
    #
    # If there are no entries for viewname in 
    # ##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##, we enforce no access restriction.
    #
    $no_restriction = (intval(pg_fetch_array($r_entries)['entry_count']) == 0) ? True : False;

    error_log("no_restriction: " . ($no_restriction ? "True" : "False"));

    $r_cookie_entries = db_query_params("
        SELECT count(*) AS cookie_match_count 
        FROM \"##PG_SCHEMA_DATACLIP_ACCESS_COOKIES##\"
        WHERE viewname = $1 AND access_cookie = $2
        ", [$viewname, $access_cookie] );

    $access_cookie_match = (intval(pg_fetch_array($r_cookie_entries)['cookie_match_count']) >= 1) ? True : False;

    error_log("access_cookie_match: " . ($access_cookie_match ? "True" : "False"));

    if ($no_restriction) {
        error_log("No restrictions for view $viewname");
        return True;
    } elseif ($access_cookie_match) {
        error_log("Access cookie matched for view $viewname");
        return True;
    } else {
        return False;
    }
}

function display_dataclip_style () 
{
    ?>
    <style type="text/css">
    .dataclip {
        background-color: #f5f5f5;
        padding: 5px;
        border-radius: 5px;
        -moz-border-radius: 5px;
        -webkit-border-radius: 5px;
        border: 1px solid #ebebeb;
    }
    .dataclip td, .dataclip th {
        padding: 1px 5px;
    }
    .dataclip thead {
        font: normal 15px Helvetica Neue,Helvetica,sans-serif;
        text-shadow: 0 1px 0 white;
        color: #999;
    }

    .dataclip tr:nth-child(even) td {
        background-color: #e8e8e8;
    }

    .dataclip tr:nth-child(even) td:hover {
        background-color: #fff;
    }

    .dataclip th {
        text-align: left;
        border-bottom: 1px solid #fff;
    }
    .dataclip td {
        font-size: 14px;
    }
    .dataclip td:hover {
        background-color: #fff;
    }
    </style>
    <?php
}

function insert_to_access_log($viewname, $access_allowed) {
    db_query_params(
        "INSERT INTO \"##PG_SCHEMA_DATACLIP_ACCESS_LOG##\" 
            (viewname, access_allowed) 
            VALUES ($1, $2)", 
        [ $viewname, $access_allowed ]);
}

function display_dataclip($viewname, $limit=100, $offset=0) {
    $limit_exceeded = False;
    $r = Null;

    if (! valid_viewname($viewname)) {
        error_log("Invalid viewname $viewname passed to display dataclip! This should never happen.");
        return null;
    } else {
        # Sadly, neither table names nor LIMIT/OFFSET values cannot be used as 
        # a query parameter. We try to prevent sql injection by double checking 
        # the viewname and offset are valid, above. 
        #
        # TODO: double check $offset
        #
        $r = db_query_params("SELECT * FROM $viewname OFFSET $offset");
    }

    $num_rows = pg_num_rows($r);
    $nf       = pg_num_fields($r);
    $field_names = array();
    for ($i = 0; $i < $nf; $i++) {
        array_push($field_names, pg_field_name($r, $i));
    }
    /*
    echo "field names: ";
    print_r($field_names);
    */


    if ($num_rows > $limit) {
        echo "<h2>Number of returned rows $num_rows exceeds limit $limit, only showing $limit rows</h2>";
    } else {
        echo "<h2>Query returned $num_rows rows</h2>";
    }

    echo '<table class="dataclip sortable">';
    echo "<thead>";
    foreach ($field_names as $f) {
        echo "<th>{$f}</th>";
    }
    echo "</thead><tbody>\n";

    for ($i = 0; ($a = pg_fetch_array($r)) and $i < $limit; $i++) {
        echo "<tr>";
        foreach ($field_names as $f) {
            echo "<td>{$a[$f]}</td>";
        }
        echo "</tr>\n";
    }

    echo "</tbody></table>\n";
}

function main() {
    global $DB_CONNECTION;

    set_request_variables_from_command_line();
    connect_to_database_or_die();

    $viewname = viewname();
    if (null === $viewname) {
        echo "<h1> viewname was not supplied, or was not a valid format for a viewname.\n";
        exit(1);
    }
    $access_cookie = access_cookie();

    if (pg_schema_dataclip_access_log_enabled()) {
        insert_to_access_log($viewname, access_allowed($viewname, $access_cookie));
    }


    echo "<html>\n";
    echo "<head>";
    echo '<script src="sorttable.js"></script>' . "\n";
    echo "<title> Data for: " . $viewname . "</title></head>\n";
    echo "<body>\n";
    
    /*
    echo '<br> access_cookie is: ' . $access_cookie . "\n";
    echo '<br> access_allowed($viewname, $access_allowed) is: ' . ( access_allowed($viewname, $access_cookie) ? "True" : "False") . "\n";
     */

    if (view_exists($viewname) and access_allowed($viewname, $access_cookie)) {
        $limit = limit();
        $offse = offset();
        echo '<h1> Data for: ' . $viewname . "</h1>\n";
        display_dataclip_style();
        display_dataclip($viewname, $limit, $offset);
    } else {
        echo "<h1>Sorry, either a view by the name of '$viewname' does not exist, or the access_cookie '$access_cookie' does not allow you access.";
    }

    echo "</body>\n";
    echo "</html>\n";

    pg_close($DB_CONNECTION);
}

main();

?>
