<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\db\mysql;

use Craft;
use craft\db\Connection;
use craft\services\Config;
use yii\db\Expression;

/**
 * @inheritdoc
 *
 * @property Connection $db Connection the DB connection that this command is associated with.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class QueryBuilder extends \yii\db\mysql\QueryBuilder
{
    /**
     * @inheritdoc
     *
     * @param string $table   the name of the table to be created. The name will be properly quoted by the method.
     * @param array  $columns the columns (name => definition) in the new table.
     * @param string $options additional SQL fragment that will be appended to the generated SQL.
     *
     * @return string the SQL statement for creating a new DB table.
     */
    public function createTable($table, $columns, $options = null)
    {
        // Default to InnoDb
        if ($options === null || strpos($options, 'ENGINE=') === false) {
            $options = ($options !== null ? $options.' ' : '').'ENGINE=InnoDb';
        }

        // Use the default charset
        if (strpos($options, 'DEFAULT CHARSET=') === false) {
            $options .= ' DEFAULT CHARSET='.Craft::$app->getConfig()->get('charset', Config::CATEGORY_DB);
        }

        return parent::createTable($table, $columns, $options);
    }

    /**
     * Builds a SQL statement for dropping a DB table if it exists.
     *
     * @param string $table The table to be dropped. The name will be properly quoted by the method.
     *
     * @return string The SQL statement for dropping a DB table.
     */
    public function dropTableIfExists($table)
    {
        return 'DROP TABLE IF EXISTS '.$this->db->quoteTableName($table);
    }

    /**
     * Builds a SQL statement for inserting some given data into a table, or updating an existing row
     * in the event of a key constraint violation.
     *
     * @param string $table               The table that the row will be inserted into, or updated.
     * @param array  $keyColumns          The key-constrained column data (name => value) to be inserted into the table
     *                                    in the event that a new row is getting created
     * @param array  $updateColumns       The non-key-constrained column data (name => value) to be inserted into the table
     *                                    or updated in the existing row.
     * @param array  $params              The binding parameters that will be generated by this method.
     *                                    They should be bound to the DB command later.
     *
     * @return string The SQL statement for inserting or updating data in a table.
     */
    public function upsert($table, $keyColumns, $updateColumns, &$params)
    {
        $schema = $this->db->getSchema();

        if (($tableSchema = $schema->getTableSchema($table)) !== null) {
            $columnSchemas = $tableSchema->columns;
        } else {
            $columnSchemas = [];
        }

        $columns = array_merge($keyColumns, $updateColumns);
        $names = [];
        $placeholders = [];
        $updates = [];

        foreach ($columns as $name => $value) {
            $qName = $schema->quoteColumnName($name);
            $names[] = $qName;

            if ($value instanceof Expression) {
                $placeholder = $value->expression;

                foreach ($value->params as $n => $v) {
                    $params[$n] = $v;
                }
            } else {
                $phName = static::PARAM_PREFIX.count($params);
                $placeholder = $phName;
                $params[$phName] = !is_array($value) && isset($columnSchemas[$name]) ? $columnSchemas[$name]->dbTypecast($value) : $value;
            }

            $placeholders[] = $placeholder;

            // Was this an update column?
            if (isset($updateColumns[$name])) {
                $updates[] = "$qName = $placeholder";
            }
        }

        return 'INSERT INTO '.$schema->quoteTableName($table).
            ' ('.implode(', ', $names).') VALUES ('.
            implode(', ', $placeholders).') ON DUPLICATE KEY UPDATE '.
            implode(', ', $updates);
    }

    /**
     * Builds a SQL statement for replacing some text with other text in a given table column.
     *
     * @param string       $table     The table to be updated.
     * @param string       $column    The column to be searched.
     * @param string       $find      The text to be searched for.
     * @param string       $replace   The replacement text.
     * @param array|string $condition the condition that will be put in the WHERE part. Please
     *                                refer to [[Query::where()]] on how to specify condition.
     * @param array        $params    The binding parameters that will be generated by this method.
     *                                They should be bound to the DB command later.
     *
     * @return string The SQL statement for replacing some text in a given table.
     */
    public function replace($table, $column, $find, $replace, $condition, &$params)
    {
        $column = $this->db->quoteColumnName($column);

        $findPhName = static::PARAM_PREFIX.count($params);
        $params[$findPhName] = $find;

        $replacePhName = static::PARAM_PREFIX.count($params);
        $params[$replacePhName] = $replace;

        $sql = "UPDATE {$table} SET {$column} = REPLACE({$column}, {$findPhName}, {$replacePhName})";
        $where = $this->buildWhere($condition, $params);

        return $where === '' ? $sql : $sql.' '.$where;
    }

    /**
     * Builds the SQL expression used to return a DB result in a fixed order.
     *
     * @param string $column The column name that contains the values.
     * @param array  $values The column values, in the order in which the rows should be returned in.
     *
     * @return string The SQL expression.
     */
    public function fixedOrder($column, $values)
    {
        $sql = 'FIELD('.$this->db->quoteColumnName($column);
        foreach ($values as $value) {
            $sql .= ','.$this->db->quoteValue($value);
        }
        $sql .= ')';

        return $sql;
    }
}