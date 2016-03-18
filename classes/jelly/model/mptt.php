<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Modified Preorder Tree Traversal Class.
 *
 * Ported for Jelly from Paul Banks' Sprig_MPTT that in turn has been ported
 * from ORM_MPTT originally created by Matthew Davies and Kiall Mac Innes
 *
 * @package Jelly_MPTT
 * @author  Mathew Davies
 * @author  Kiall Mac Innes
 * @author  Paul Banks
 * @author  Alexander Kupreyeu (Kupreev) (alexander dot kupreev at gmail dot com, http://kupreev.com)
 */
abstract class Jelly_Model_MPTT extends Jelly_Model
{

    /**
     * @access protected
     * @var string mptt view folder.
     */
    protected $_directory = 'mptt';

    /**
     * @access protected
     * @var string default view folder.
     */
    protected $_style = 'default';

    protected $_left_column = NULL;
    protected $_right_column = NULL;
    protected $_level_column = NULL;
    protected $_scope_column = NULL;

    /**
     * Initialize the fields and add MPTT field defaults if not specified
     * @param  array  $values -> geändert von default = array() auf default = NULL, weil sonst
     * der Aufruf von construct ein Array weitergibt (leeres) und Jelly dann denkt, es handelt sich
     * um ein Key, weil es sonst NULL erwartet
     * @return void
     */
    public function __construct($values = NULL)
    {
        // Initialize jelly model
        parent::__construct($values);

        // Check we have default values for all (MPTT) fields (otherwise we cause errors)
        foreach ($this->meta()->fields() as $name => $field)
        {
            if ($field instanceof Jelly_Field_MPTT AND !isset($this->_original[$name]))
            {
                $this->_original[$name] = NULL;
            }
        }

        $this->_left_column = $this->meta()
            ->field('left')->column;
        $this->_right_column = $this->meta()
            ->field('right')->column;
        $this->_level_column = $this->meta()
            ->field('level')->column;
        $this->_scope_column = $this->meta()
            ->field('scope')->column;
    }

    public static function initialize(Jelly_Meta $meta)
    {

        $meta->fields(array(
            //'id' => new Field_Primary,


            'left' => new Jelly_Field_MPTT_Left(array(
                'column' => 'left'
            )),
            'right' => new Jelly_Field_MPTT_Right(array(
                'column' => 'right'
            )),
            'level' => new Jelly_Field_MPTT_Level(array(
                'column' => 'level'
            )),
            'scope' => new Jelly_Field_MPTT_Scope(array(
                'column' => 'scope'
            ))
        ));

        // Check we don't have a composite primary Key
        if (is_array($meta->primary_key()))
        {
            throw new Kohana_Exception('Jelly_MPTT does not support composite primary keys');
        }

    }


    /**
     * Locks table.
     *
     * @access private
     */
    protected function lock()
    {
        Database::instance($this->db)->query(NULL, 'LOCK TABLE ' . Database::instance($this->db)->table_prefix() . $this->table . ' WRITE', TRUE);
    }

    /**
     * Unlock table.
     *
     * @access private
     */
    protected function unlock()
    {
        Database::instance($this->db)->query(NULL, 'UNLOCK TABLES', TRUE);
    }

    /**
     * Does the current node have children?
     *
     * @access public
     * @return bool
     */
    public function has_children()
    {
        return (($this->right - $this->left) > 1);
    }

    /**
     * Is the current node a leaf node?
     *
     * @access public
     * @return bool
     */
    public function is_leaf()
    {
        return !$this->has_children();
    }

    /**
     * Is the current node a descendant of the supplied node.
     *
     * @access public
     * @param Jelly_Model_MPTT $target Target
     * @return bool
     */
    public function is_descendant($target)
    {
        return (
            $this->left > $target->left
            AND $this->right < $target->right
            AND $this->scope == $target->scope
        );
    }

    /**
     * Is the current node a direct child of the supplied node?
     *
     * @access public
     * @param Jelly_Model_MPTT $target Target
     * @return bool
     */
    public function is_child($target)
    {
        return ($this->parent->{$this->meta()->primary_key()} === $target->{$this->meta()->primary_key()});
    }

    /**
     * Is the current node the direct parent of the supplied node?
     *
     * @access public
     * @param Jelly_Model_MPTT $target Target
     * @return bool
     */
    public function is_parent($target)
    {
        return ($this->{$this->meta()->primary_key()} === $target->parent->{$this->meta()->primary_key()});
    }

    /**
     * Is the current node a sibling of the supplied node
     *
     * @access public
     * @param Jelly_Model_MPTT $target Target
     * @return bool
     */
    public function is_sibling($target)
    {
        if ($this->{$this->meta()->primary_key()} === $target->{$this->meta()->primary_key()})
            return FALSE;

        return ($this->parent->{$this->meta()->primary_key()} === $target->parent->{$this->meta()->primary_key()});
    }

    /**
     * Is the current node a root node?
     *
     * @access public
     * @return bool
     */
    public function is_root()
    {
        return ($this->left === 1);
    }

    /**
     * Returns the root node.
     *
     * @access public
     * @return Jelly_Model_MPTT/FALSE on invalid scope
     */
    public function root($scope = NULL)
    {
        if ($scope === NULL AND $this->loaded())
        {
            $scope = $this->scope;
        }
        elseif ($scope === NULL AND !$this->loaded())
        {
            return FALSE;
        }

        return Jelly::query($this)->where($this->_left_column, '=', 1)
            ->where($this->_scope_column, '=', $scope)
            ->limit(1)
            ->execute();
    }

    /**
     * Returns the parent of the current node.
     *
     * @access public
     * @return Jelly_Model_MPTT
     */
    public function parent()
    {
        return $this->parents(TRUE, 'ASC', TRUE);
    }

    /**
     * Returns the parents of the current node.
     *
     * @access public
     * @param bool   $root      include the root node?
     * @param string $direction direction to order the left column by.
     * @param bool   $direct_parent_only
     * @return Jelly_Model_MPTT
     */
    public function parents($root = TRUE, $direction = 'ASC', $direct_parent_only = FALSE)
    {
        $query = Jelly::query($this)->where($this->_left_column, '<=', $this->left)
            ->where($this->_right_column, '>=', $this->right)
            ->where($this->meta()->primary_key(), '<>', $this->{$this->meta()->primary_key()})
            ->where($this->_scope_column, '=', $this->scope)
            ->order_by($this->_left_column, $direction);

        if (!$root)
        {
            $query->where($this->_left_column, '!=', 1);
        }

        if ($direct_parent_only)
        {
            $query
                ->where($this->_level_column, '=', $this->level - 1)
                ->limit(1);
        }

        $parents = $query->execute();

        return $parents;
    }

    /**
     * Returns the children of the current node.
     *
     * @access public
     * @param bool     $self      include the current loaded node?
     * @param string   $direction direction to order the left column by.
     * @param int|bool $limit
     * @return Jelly_Model_MPTT
     */
    public function children($self = FALSE, $direction = 'ASC', $limit = FALSE)
    {
        return $this->descendants($self, $direction, TRUE, FALSE, $limit);
    }

    /**
     * Returns the descendants of the current node.
     *
     * @access public
     * @param bool     $self      include the current loaded node?
     * @param string   $direction direction to order the left column by.
     * @param bool     $direct_children_only
     * @param bool     $leaves_only
     * @param int|bool $limit
     * @return Jelly_Model_MPTT
     */
    public function descendants($self = FALSE, $direction = 'ASC', $direct_children_only = FALSE, $leaves_only = FALSE, $limit = FALSE)
    {
        $left_operator = $self ? '>=' : '>';
        $right_operator = $self ? '<=' : '<';

        $query = Jelly::query($this)->where($this->_left_column, $left_operator, $this->left)
            ->where($this->_right_column, $right_operator, $this->right)
            ->where($this->_scope_column, '=', $this->scope)
            ->order_by($this->_left_column, $direction);

        if ($direct_children_only)
        {
            if ($self)
            {
                $query->and_where_open()
                    ->where($this->_level_column, '=', $this->level)
                    ->or_where($this->_level_column, '=', $this->level + 1)
                    ->and_where_close();
            }
            else
            {
                $query->where($this->_level_column, '=', $this->level + 1);
            }
        }

        if ($leaves_only)
        {
            $query->where($this->_right_column, '=', new Database_Expression('`' . $this->_left_column . '` + 1'));
        }

        if ($limit)
        {
            $query->limit($limit);
        }

        return $query->execute();
    }

    /**
     * Returns the siblings of the current node
     *
     * @access public
     * @param bool   $self      include the current loaded node?
     * @param string $direction direction to order the left column by.
     * @return Jelly_Model_MPTT
     */
    public function siblings($self = FALSE, $direction = 'ASC')
    {
        $query = Jelly::query($this)->where($this->_left_column, '>', $this->parent->left)
            ->where($this->_right_column, '<', $this->parent->right)
            ->where($this->_scope_column, '=', $this->scope)
            ->where($this->_level_column, '=', $this->level)
            ->order_by($this->_left_column, $direction);

        if (!$self)
        {
            $query->where($this->meta()
                ->primary_key(), '<>', $this->{$this->meta()
                ->primary_key()});
        }

        return $query->execute();
    }

    /**
     * Returns leaves under the current node.
     *
     * @access public
     * @param bool   $self      include the current loaded node?
     * @param string $direction direction to order the left column by.
     * @return Jelly_Model_MPTT
     */
    public function leaves($self = FALSE, $direction = 'ASC')
    {
        return $this->descendants($self, $direction, TRUE, TRUE);
    }

    /**
     * Get Size
     *
     * @access protected
     * @return integer
     */
    protected function get_size()
    {
        return ($this->right - $this->left) + 1;
    }

    /**
     * Create a gap in the tree to make room for a new node
     *
     * @access private
     * @param integer $start start position.
     * @param integer $size  the size of the gap (default is 2).
     * @return void
     */
    private function create_space($start, $size = 2)
    {
        // Update the left values, then the right.
        DB::update($this->table)
            ->set(array(
                $this->_left_column => new Database_Expression('`' . $this->_left_column . '` + ' . $size)
            ))
            ->where($this->_left_column, '>=', $start)
            ->where($this->_scope_column, '=', $this->scope)
            ->execute($this->db);

        DB::update($this->table)
            ->set(array(
                $this->_right_column => new Database_Expression('`' . $this->_right_column . '` + ' . $size)
            ))
            ->where($this->_right_column, '>=', $start)
            ->where($this->_scope_column, '=', $this->scope)
            ->execute($this->db);
    }

    /**
     * Closes a gap in a tree. Mainly used after a node has
     * been removed.
     *
     * @access private
     * @param integer $start start position.
     * @param integer $size  the size of the gap (default is 2).
     * @return void
     */
    private function delete_space($start, $size = 2)
    {
        // Update the left values, then the right.
        DB::update($this->table)
            ->set(array(
                $this->_left_column => new Database_Expression('`' . $this->_left_column . '` - ' . $size)
            ))
            ->where($this->_left_column, '>=', $start)
            ->where($this->_scope_column, '=', $this->scope)
            ->execute($this->db);

        DB::update($this->table)
            ->set(array(
                $this->_right_column => new Database_Expression('`' . $this->_right_column . '` - ' . $size)
            ))
            ->where($this->_right_column, '>=', $start)
            ->where($this->_scope_column, '=', $this->scope)
            ->execute($this->db);
    }

    /**
     * Insert this object as the root of a new scope
     *
     * Other object fields must be set in the normal Jelly way
     * otherwise validation exception will be thrown
     *
     * @param integer $scope New scope to create.
     * @return Jelly_Model_MPTT
     * @throws Validation_Exception on invalid $additional_fields data
     **/
    public function insert_as_new_root($scope = NULL)
    {
        $this->lock();

        if ($scope === NULL)
        {
            // Umstellung auf DB::select(), damit Table-Prefix angewendet werden kann
            $arrResult = Db::select(array(
                Db::expr('MAX(scope) + 1'),
                'new_scope'
            ))->from($this->meta()
                ->table())
                ->execute(NULL, FALSE)
                ->offsetGet(0);
//            $arrResult = Database::instance()->query(Database::SELECT, 'select MAX(scope) + 1 as new_scope from ' . $this->meta()
//                ->table() . ';')
//                ->offsetGet(0);
            // wenn noch kein Eintrag drinne, kommt NULL zurück, deshalb 1 als default
            $scope = Arr::get($arrResult, 'new_scope', 1);

        }
        // Make sure the specified scope doesn't already exist.
        $root = $this->root($scope);

        if ($root->loaded())
        {
            $this->unlock();
            return FALSE;
        }

        // Create a new root node in the new scope.
        $this->set(array(
            'left' => 1,
            'right' => 2,
            'level' => 0,
            'scope' => $scope
        ));

        try
        {
            //parent::create();
            $this->save();
        } catch (Validate_Exception $e)
        {
            $this->unlock();
            // There was an error validating the additional fields, re-thow it
            throw $e;
        }

        $this->unlock();
        return $this;
    }

    /**
     * Insert the object
     *
     * @access protected
     * @param Jelly_MPTT|mixed $target         target node primary key value or Jelly_MPTT object.
     * @param string           $copy_left_from target object property to take new left value from
     * @param integer          $left_offset    offset for left value
     * @param integer          $level_offset   offset for level value
     * @return Jelly_Model_MPTT
     * @throws Validation_Exception
     */
    protected function insert($target, $copy_left_from, $left_offset, $level_offset)
    {
        // Insert should only work on new nodes.. if its already it the tree it needs to be moved!
        if ($this->loaded())
            return FALSE;

        if (!$target instanceof $this)
        {
            $target = Jelly::query($this)->load($target);

            if (!$target->loaded())
            {
                return FALSE;
            }
        }
        else
        {
            $target->reload();
        }

        $this->lock();

        $this->set(array(
            'left' => $target->{$copy_left_from} + $left_offset,
            'right' => $target->{$copy_left_from} + $left_offset + 1,
            'level' => $target->level + $level_offset,
            'scope' => $target->scope,
        ));

        $this->create_space($this->left);

        try
        {
            $this->save();
        } catch (Exception $e)
        {
            // We had a problem creating - make sure we clean up the tree
            $this->delete_space($this->left);
            $this->unlock();
            throw $e;
        }

        $this->unlock();

        return $this;
    }

    /**
     * Inserts a new node as the first child of the target node
     *
     * @access public
     * @param Jelly_Model_MPTT|mixed $target target node primary key value or Jelly_MPTT object.
     * @return  Jelly_Model_MPTT
     */
    public function insert_as_first_child($target)
    {
        return $this->insert($target, 'left', 1, 1);
    }

    /**
     * Inserts a new node as the last child of the target node
     *
     * @access public
     * @param Jelly_Model_MPTT|mixed $target target node primary key value or Jelly_Model_MPTT object.
     * @return Jelly_Model_MPTT
     */
    public function insert_as_last_child($target)
    {
        return $this->insert($target, 'right', 0, 1);
    }

    /**
     * Inserts a new node as a previous sibling of the target node.
     *
     * @access public
     * @param Jelly_Model_MPTT|integer $target target node id or Jelly_Model_MPTT object.
     * @return Jelly_Model_MPTT
     */
    public function insert_as_prev_sibling($target)
    {
        return $this->insert($target, 'left', 0, 0);
    }

    /**
     * Inserts a new node as the next sibling of the target node.
     *
     * @access public
     * @param Jelly_Model_MPTT|integer $target target node id or Jelly_Model_MPTT object.
     * @return Jelly_Model_MPTT
     */
    public function insert_as_next_sibling($target)
    {
        return $this->insert($target, 'right', 1, 0);
    }

    /**
     * Overloaded create method
     *
     * @access public
     * @return void
     * @throws Validation_Exception
     */
    public function create()
    {
        // Don't allow creation directly as it will invalidate the tree
        throw new Kohana_Exception('You cannot use create() on Jelly_MPTT model :name. Use an appropriate insert_* method instead',
            array(':name' => get_class($this)));
    }

    /**
     * Removes a node and it's descendants.
     *
     * @access public
     */
    public function delete_obj(Database_Query_Builder_Delete $query = NULL)
    {
        if ($query !== NULL)
        {
            throw new Kohana_Exception('Jelly_MPTT does not support passing a query object to delete()');
        }

        $this->lock();

        // Handle un-foreseen exceptions
        try
        {
            DB::delete($this->table)
                ->where($this->_left_column, '>=', $this->left)
                ->where($this->_right_column, '<=', $this->right)
                ->where($this->_scope_column, '=', $this->scope)
                ->execute($this->db);

            $this->delete_space($this->left, $this->get_size());
        } catch (Exception $e)
        {
            //Unlock table and re-throw exception
            $this->unlock();
            throw $e;
        }

        $this->unlock();
    }

    /**
     * Overloads the select_list method to
     * support indenting.
     *
     * Returns all recods in the current scope
     *
     * @param string $key    first table column.
     * @param string $val    second table column.
     * @param string $indent character used for indenting.
     * @return array
     */
    public function select_list($key = 'id', $value = 'name', $indent = NULL)
    {
        $result = DB::select($key, $value, $this->_level_column)->from($this->table)
            ->where($this->_scope_column, '=', $this->scope)
            ->order_by($this->_left_column, 'ASC')
            ->execute($this->db);

        if (is_string($indent))
        {
            $array = array();

            foreach ($result as $row)
            {
                $array[$row[$key]] = str_repeat($indent, $row[$this->_level_column]) . $row[$value];
            }

            return $array;
        }

        return $result->as_array($key, $value);
    }

    /**
     * Move to First Child
     *
     * Moves the current node to the first child of the target node.
     *
     * @param Jelly_Model_MPTT|integer $target target node id or Jelly_Model_MPTT object.
     * @return Jelly_Model_MPTT
     */
    public function move_to_first_child($target)
    {
        return $this->move($target, TRUE, 1, 1, TRUE);
    }

    /**
     * Move to Last Child
     *
     * Moves the current node to the last child of the target node.
     *
     * @param Jelly_Model_MPTT|integer $target target node id or Jelly_Model_MPTT object.
     * @return Jelly_Model_MPTT
     */
    public function move_to_last_child($target)
    {
        return $this->move($target, FALSE, 0, 1, TRUE);
    }

    /**
     * Move to Previous Sibling.
     *
     * Moves the current node to the previous sibling of the target node.
     *
     * @param Jelly_Model_MPTT|integer $target target node id or Jelly_Model_MPTT object.
     * @return Jelly_Model_MPTT
     */
    public function move_to_prev_sibling($target)
    {
        return $this->move($target, TRUE, 0, 0, FALSE);
    }

    /**
     * Move to Next Sibling.
     *
     * Moves the current node to the next sibling of the target node.
     *
     * @param Jelly_Model_MPTT|integer $target target node id or Jelly_Model_MPTT object.
     * @return Jelly_Model_MPTT
     */
    public function move_to_next_sibling($target)
    {
        return $this->move($target, FALSE, 1, 0, FALSE);
    }

    /**
     * Move
     *
     * @param Jelly_Model_MPTT|integer $target            target node id or Jelly_Model_MPTT object.
     * @param bool                     $left_column       use the left column or right column from target
     * @param integer                  $left_offset       left value for the new node position.
     * @param integer                  $level_offset      level
     * @param bool                     $allow_root_target allow this movement to be allowed on the root node
     * @return  Jelly_Model_MPTT|bool $this
     */
    protected function move($target, $left_column, $left_offset, $level_offset, $allow_root_target)
    {
        if (!$this->loaded())
            return FALSE;

        // Make sure we have the most upto date version of this AFTER we lock
        $this->lock();
        $this->reload();

        // Catch any database or other excpetions and unlock
        try
        {
            if (!$target instanceof $this)
            {
                $target = Jelly::query($this)->load($target);

                if (!$target->loaded())
                {
                    $this->unlock();
                    return FALSE;
                }
            }
            else
            {
                $target->reload();
            }

            // Stop $this being moved into a descendant or itself or disallow if target is root
            if ($target->is_descendant($this)
                OR $this->{$this->meta()->primary_key()} === $target->{$this->meta()->primary_key()}
                OR ($allow_root_target === FALSE AND $target->is_root())
            )
            {
                $this->unlock();
                return FALSE;
            }

            $left_offset = ($left_column === TRUE ? $target->left : $target->right) + $left_offset;
            $level_offset = $target->level - $this->level + $level_offset;

            $size = $this->get_size();

            $this->create_space($left_offset, $size);

            // if node is moved to a position in the tree "above" its current placement
            // then its lft/rgt may have been altered by create_space
            $this->reload();

            $offset = ($left_offset - $this->left);

            // Update the values.
            DB::update($this->table)
                ->set(array(
                    $this->_left_column => DB::expr('`' . $this->_left_column . '` + ' . $offset),
                    $this->_right_column => DB::expr('`' . $this->_right_column . '` + ' . $offset),
                    $this->_level_column => DB::expr('`' . $this->_level_column . '` + ' . $level_offset),
                    $this->_scope_column => $target->scope
                ))
                ->where($this->_left_column, '>=', $this->left)
                ->and_where($this->_right_column, '<=', $this->right)
                ->and_where($this->_scope_column, '=', $this->scope)
                ->execute($this->db);

            $this->delete_space($this->left, $size);
        } catch (Exception $e)
        {
            //Unlock table and re-throw exception
            $this->unlock();
            throw $e;
        }

        $this->unlock();

        return $this;
    }

    /**
     *
     * @access public
     * @param $column - Which field to get.
     * @return mixed
     */
    public function __get($column)
    {
        switch ($column)
        {
            case 'parent':
                return $this->parent();
            case 'parents':
                return $this->parents();
            case 'children':
                return $this->children();
            case 'first_child':
                return $this->children(FALSE, 'ASC', 1);
            case 'last_child':
                return $this->children(FALSE, 'DESC', 1);
            case 'siblings':
                return $this->siblings();
            case 'root':
                return $this->root();
            case 'leaves':
                return $this->leaves();
            case 'descendants':
                return $this->descendants();
            /*case 'left_column':
                return $this->meta()->left_column;
            case 'right_column':
                return $this->meta()->right_column;
            case 'level_column':
                return $this->meta()->level_column;
            case 'scope_column':
                return $this->meta()->scope_column; */
            case 'db':
                return $this->meta()->db();
            case 'table':
                return $this->meta()->table();
            default:
                return parent::__get($column);
        }
    }

    /**
     * Verify the tree is in good order
     *
     * This functions speed is irrelevant - its really only for debugging and unit tests
     *
     * @todo   Look for any nodes no longer contained by the root node.
     * @todo   Ensure every node has a path to the root via ->parents();
     * @access public
     * @return boolean
     */
    public function verify_tree()
    {
        foreach ($this->get_scopes() as $scope)
        {
            if (!$this->verify_scope($scope->scope))
                return FALSE;
        }
        return TRUE;
    }

    private function get_scopes()
    {
        // TODO... redo this so its proper :P and open it public
        // used by verify_tree()
        //return DB::select()->as_object()->distinct($this->scope_column)->from($this->table)->execute($this->db);

        return DB::select($this->_scope_column)->distinct(TRUE)->from($this->table)->as_object()->execute($this->db);
    }

    public function verify_scope($scope)
    {
        $root = $this->root($scope);

        $end = $root->right;

        // Find nodes that have slipped out of bounds.
        $count = DB::select(array(Db::expr('count("*")'), 'count'))
            ->from($this->table)
            ->where($this->_scope_column, '=', $root->scope)
            ->and_where_open($this->_left_column, '>', $end)
            ->or_where($this->_right_column, '>', $end)
            ->and_where_close()
            ->execute($this->db)
            ->get('count');
        if ($count > 0)
            return FALSE;

        // Find nodes that right value is less or equal as the left value
        $count = DB::select(array(Db::expr('count("*")'), 'count'))
            ->from($this->table)
            ->where($this->_scope_column, '=', $root->scope)
            ->and_where($this->_left_column, '>=', DB::expr('`' . $this->_right_column . '`'))
            ->execute($this->db)
            ->get('count');
        if ($count > 0)
            return FALSE;

        // Make sure no 2 nodes share a left/right value
        $i = 1;
        while ($i <= $end)
        {
            // TODO optimize request
            $result = Database::instance($this->db)->query(Database::SELECT, 'SELECT count(*) as count FROM `' . Database::instance($this->db)->table_prefix() . $this->table . '`
				WHERE `' . $this->_scope_column . '` = ' . $root->scope . '
				AND (`' . $this->_left_column . '` = ' . $i . ' OR `' . $this->_right_column . '` = ' . $i . ')', TRUE);

            if ($result[0]->count > 1)
                return FALSE;

            $i++;
        }

        // Check to ensure that all nodes have a "correct" level

        return TRUE;
    }

    /**
     * Force object to reload MPTT fields from database
     *
     * @return $this
     */
    public function reload()
    {
        if (!$this->loaded())
        {
            return FALSE;
        }

        $mptt_vals = DB::select(
            $this->_left_column,
            $this->_right_column,
            $this->_level_column,
            $this->_scope_column
        )
            ->from($this->table)
            ->where($this->meta()->primary_key(), '=', $this->{$this->meta()->primary_key()})
            ->execute($this->db)
            ->current();

        //return $this->values($mptt_vals);
        return $this->set($mptt_vals);
    }

    /**
     * Generates the HTML for this node's descendants
     *
     * @param string  $style     pagination style.
     * @param boolean $self      include this node or not.
     * @param string  $direction direction to order the left column by.
     * @return View
     */
    public function render_descendants($style = NULL, $self = FALSE, $direction = 'ASC')
    {
        $nodes = $this->descendants($self, $direction);

        if ($style === NULL)
        {
            $style = $this->_style;
        }

        return View::factory($this->_directory . DIRECTORY_SEPARATOR . $style, array('nodes' => $nodes, 'level_column' => 'level'));
    }

    /**
     * Generates the HTML for this node's children
     *
     * @param string  $style     pagination style.
     * @param boolean $self      include this node or not.
     * @param string  $direction direction to order the left column by.
     * @return View
     */
    public function render_children($style = NULL, $self = FALSE, $direction = 'ASC')
    {
        $nodes = $this->children($self, $direction);

        if ($style === NULL)
        {
            $style = $this->_style;
        }

        return View::factory($this->_directory . DIRECTORY_SEPARATOR . $style, array('nodes' => $nodes, 'level_column' => 'level'));
    }

    /*********************  MEINE ERWEITERUNGEN **************************/

    /**
     * legt eine Kopie des entsprechenden Root-Knotens (this) an
     * @param Model_User $objUser
     * @throws Dokma_Exception
     */
    public function copy($objUser)
    {
        if (!$this->is_root())
        {
            throw new Dokma_Exception('Copy auf NICHT-Root-Knoten aufgerufen');
        }

        // kreiere neuen Root
        $objModel = $this->meta()
            ->model();
        // kopiere den alten root
        $objNewRoot = Jelly::factory($objModel)->set($this->as_array(array(
            'structure_id',
            'name',
            'type',
            'default_text',
            'config',
            'is_searchable',
            'is_readonly',
            'left',
            'right',
            'level'
        )));

        $objNewRoot->create_user = $objUser->id;

        // speichere ihn ab
        $objNewRoot->insert_as_new_root();
        $objNewRoot->set(array(
            'left' => $this->left,
            'right' => $this->right
        ))
            ->save();

        // nun alle siblings
        foreach ($this->descendants() as $objDescendant)
        {
            $objNewDescendant = Jelly::factory($objModel)->set($objDescendant->as_array(array(
                'structure_id',
                'name',
                'type',
                'default_text',
                'config',
                'is_searchable',
                'is_readonly',
                'left',
                'right',
                'level'
            )));
            $objNewDescendant->scope = $objNewRoot->scope;
            $objNewDescendant->create_user = $objUser->id;
            $objNewDescendant->save();
        }

        return $objNewRoot;
    }

    /**
     *
     * liefert den nächsten Knoten auf dieser Hierarchie
     * @return mptt
     */
    public function next_sibling()
    {
        return Jelly::query($this)->where($this->_left_column, '>', $this->left)
            ->where($this->_scope_column, '=', $this->scope)
            ->order_by($this->_left_column, 'ASC')
            ->limit(1)
            ->execute();
    }

    /**
     *
     * liefert den vorherigen Knoten auf dieser Hierarchie
     * @return mptt
     */
    public function prev_sibling()
    {
        return Jelly::query($this)->where($this->_left_column, '<', $this->left)
            ->where($this->_scope_column, '=', $this->scope)
            ->order_by($this->_left_column, 'DESC')
            ->limit(1)
            ->execute();
    }

    /**
     * EXPERIMENTELL: Erst nur für Model_Row_Status_Network gecheckt!!!
     *
     * Diese Methode checkt, ob sich was an der Hierarchie geändert hat.
     *
     * Dazu schaut sie in alle Children und überprüft die auch.
     *
     * @param array $arrPost - muss dem Standard entsprechen
     *
     * @return boolean
     */
    public function hasChanged($arrPost)
    {
        // wenn neu, dann def. changed
        if (!$this->loaded())
        {
            return TRUE;
        }

        $this->set($arrPost);
        if ($this->changed())
        {
            return TRUE;
        }

        foreach (Arr::get($arrPost, 'structure', array()) as $intKey => $arrStructureData)
        {
            if ($intKey < 0)
            {
                // neuer Absatz
                return TRUE;
            }

            if (Arr::get($arrStructureData, 'is_deleted'))
            {
                // Absatz gelöscht
                return TRUE;
            }

            // checke den Inhalt
            //            $objStructure = Jelly::factory('Row_Organisational_Structure', $intKey);
            $objStructure = Jelly::factory($this->meta()
                ->model(), $intKey);

            // unit_id
            $objStructure->set($arrStructureData);
            if ($objStructure->changed())
            {
                return TRUE;
            }
        }

        // checke nun die Hierarchie auf Änderungen
        $arrHierarchy = json_decode(Arr::get($arrPost, 'hierarchy'), TRUE);
        if ($arrHierarchy === NULL)
        {
            // es muss immer einen geben
            throw new Dokma_Exception('Hierarchie-Daten nicht gefunden.');
        }

        // jetzt noch die Hierarchy
        //        return $this->hasHierarchyChanged(Arr::path($arrHierarchy, '0.0.children.0', array()), $this);
        // Ist das Spezialanpassung?
        // Habe beim Dokumenten-Status alle Children gleich in der obersten Hierarchie...
        // @todo: check, wie bei den anderen
        return $this->hasHierarchyChanged(Arr::get($arrHierarchy, 0, array()), $this);
    }

    /**
     * Kopie + Anpassung von Model_Row_Organisational_Structure->saveHierarchy.
     *
     * Benötige eigentlich keine Rekursion, aber im Sinne eines evtl. mal vorzunehmenden
     * Refactoring...
     *
     * @param array                    $arrHierarchy - Children-Daten zu diesem Knoten
     * @param array                    $arrPost
     * @param Model_Row_Status_Network $objParentNode
     * @param Model_User               $objUser
     * @return boolean TRUE - es hat sich etwas geändert
     */
    public function saveHierarchy($arrHierarchy, $arrPost, $objParentNode, $objUser, $boolForceNew = TRUE)
    {
        $boolChanged = $boolForceNew;
        $objPrevSibling = NULL;

        // gehe die Hierarchie der POST-Knoten durch
        foreach ($arrHierarchy as $intIndex => $arrNode)
        {
            // die Id wird von sortable extra abgelegt
            // negativ bedeutet 'neu'
            $intId = Arr::get($arrNode, 'id', -1);

            // wenn neu ...
            if ($boolForceNew)
            {
                // ... dann leeres Objekt
                $objNode = Jelly::factory($this->meta()
                    ->model());
            }
            else
            {
                // hole den Orginal-Knoten
                $objNode = Jelly::factory($this->meta()
                    ->model(), $intId);
            }
            // kopiere alles von alt auf neu
            //            if ($boolForceNew && $objNode->loaded())
            //            {
            //                $objNode = Jelly::factory($this->meta()
            //                    ->model())->set($objNode->as_array());
            //                // ... ausser die Id
            //                if ($objNode->id())
            //                {
            //                    $objNode->id = NULL;
            //                }
            //                $this->set($this->_left_column, NULL);
            //                $this->set($this->_right_column, NULL);
            //                $this->set($this->_level_column, NULL);
            //                $this->set($this->_scope_column, NULL);
            //            }
            // setze explizit die Id nochmal, um auch negative Id's verwenden zu können und
            // dann auf die $arrPost-Daten zugreifen zu können
            // negative Id bedeutet neuer Knoten


            // wenn negative Id: neuer Knoten -> nicht aus der DB geladen
            // setze notwendige Variablen, um die weitere Baumverarbeitung korrekt
            // durchführen zu können
            if ($intId < 0)
            {
                $boolChanged = TRUE;
                $objNode->set(array(
                    'id' => $intId,
                    'scope' => $objParentNode->scope,
                    'create_user' => $objUser->id
                ));
            }

            // wenn der Oberknoten schon gelöscht wurde
            if ($objParentNode->is_deleted)
            {
                // dann diesen auch so markieren
                $objNode->is_deleted = TRUE;
                $boolChanged = TRUE;
            }
            else
            {
                // sonst alle POST-Daten in diesen Knoten
                // Template-Methode
                $objNode->setDataFromPost(Arr::path($arrPost, 'structure.' . $intId), $objUser);
                $boolChanged = $objNode->changed();
            }

            // wurde er gelöscht?
            if ($objNode->is_deleted)
            {
                $boolChanged = TRUE;
                if ($objNode->loaded())
                {
                    $objNode->delete_obj();
                }
            }
            else
            {
                // sonst in die hierarchie einfügen
                if ($objPrevSibling)
                {
                    if ($objNode->loaded())
                    {
                        $objNode->save()
                            ->move_to_next_sibling($objPrevSibling);
                    }
                    else
                    {
                        $boolChanged = TRUE;
                        $objNode->insert_as_next_sibling($objPrevSibling);
                    }
                }
                else
                {
                    if ($objNode->loaded())
                    {
                        $objNode->save()
                            ->move_to_first_child($objParentNode);
                    }
                    else
                    {
                        $boolChanged = TRUE;
                        $objNode->insert_as_first_child($objParentNode);
                    }
                }
            }

            // wenn kinder, dann hinabsteigen
            // auch wenn der Knoten gelöscht wurde. kann sein, dass es ein neuer Knoten war und der User
            // einen DB-Knoten angehängt hat, dann ist dieser noch in der DB.
            $arrChildren = Helper_Sortable::getChildArr($arrNode); //Arr::path($arrNode, 'children.0');
            if ($arrChildren)
            {
                if ($this->saveHierarchy($arrChildren, $arrPost, $objNode, $objUser))
                {
                    $boolChanged = TRUE;
                }
            }

            if (!$objNode->is_deleted)
            {
                $objPrevSibling = $objNode;
            }
        }
        return $boolChanged;
    }

    /**
     * Template-Methode, die von saveHierarchy aufgerufen werden wird.
     *
     * Sie kann die POST-Daten beim Setzen manipulieren.
     * @param array $arrPost
     */
    public function setDataFromPost($arrPost, $objUser)
    {
        $this->set($arrPost);
        $this->set('create_user', $objUser->id);
    }

    /**
     * Diese Metode checkt anhand der POST-Daten und der DB-Struktur, ob es Änderungen an der Struktur gibt NICHTS WEITER (Inhalte o.ä.)!
     * Auf neu/gelöscht brauche ich nicht achten, weil das schon in Model_Row_Type::isChanged() abgefangen wurde.
     *
     * @param array                        - jquery.sorted - Array der Hierarchie auf dieser Ebene (und drunter)
     * @param Model_Row_Document_Structure - parent Knoten
     *
     * @return boolean
     */
    public function hasHierarchyChanged($arrHierarchy, $objParentNode)
    {
        $objPrevSibling = NULL;

        // gehe die Hierarchie der POST-Knoten durch
        foreach ($arrHierarchy as $intId => $arrNode)
        {
            // hole den Knoten
            $objNode = Jelly::factory($this->meta()
                ->model(), Arr::get($arrNode, 'id'));

            // wenn parent nicht der parent ist
            if (!$objParentNode->is_parent($objNode))
            {
                return TRUE;
            }

            // sonst test auf gleicher Ebene
            if ($objPrevSibling)
            {
                // hab noch keine prev sibling:
                // checke, ob ich der erste sibling bin
                if ($objPrevSibling->right != $objNode->left - 1)
                {
                    return TRUE;
                }
            }
            else
            {
                // schaue, ob ich der direkte sibling des vorherigen bin
                if ($objParentNode->left != $objNode->left - 1)
                {
                    return TRUE;
                }
            }

            // wenn kinder, dann hinabsteigen
            $arrChildren = Helper_Sortable::getChildArr($arrNode); //Arr::path($arrNode, 'children.0');
            if ($arrChildren)
            {
                if ($this->hasHierarchyChanged($arrChildren, $objNode))
                {
                    return TRUE;
                }
            }

            $objPrevSibling = $objNode;
        }

        return FALSE;
    }

}
