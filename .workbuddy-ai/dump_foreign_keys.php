<?php

/**
 * Foreign keys only, as `child|column|parent` — parsed by
 * `check_class_diagram.py`.
 *
 * A file rather than a string built in Python: a `""" ... """` query inside a
 * PHP snippet inside a Python f-string has three quoting levels and two of them
 * disagreed. The same lesson as `python -c` in PROGRESS.md §5 — put it in a file.
 */

use Illuminate\Support\Facades\DB;

$sql = "select tc.table_name as child, kcu.column_name as child_column,
               ccu.table_name as parent
        from information_schema.table_constraints tc
        join information_schema.key_column_usage kcu
          on kcu.constraint_name = tc.constraint_name
         and kcu.table_schema = tc.table_schema
        join information_schema.constraint_column_usage ccu
          on ccu.constraint_name = tc.constraint_name
         and ccu.table_schema = tc.table_schema
        where tc.constraint_type = 'FOREIGN KEY'
          and tc.table_schema = 'public'";

foreach (DB::select($sql) as $row) {
    echo $row->child.'|'.$row->child_column.'|'.$row->parent.PHP_EOL;
}
