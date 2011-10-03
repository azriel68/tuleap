<?php
/**
 * Copyright (c) Xerox Corporation, Codendi Team, 2001-2009. All rights reserved
 *
 * This file is a part of Codendi.
 *
 * Codendi is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Codendi is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Codendi. If not, see <http://www.gnu.org/licenses/>.
 */

require_once('common/dao/include/DataAccessObject.class.php');

class Tracker_FormElement_Field_List_Bind_Static_ValueDao extends DataAccessObject {
    function __construct() {
        parent::__construct();
        $this->table_name = 'tracker_field_list_bind_static_value';
    }
    
    public function searchById($id) {
        $id  = $this->da->escapeInt($id);
        $sql = "SELECT *
                FROM $this->table_name
                WHERE id = $id";
        return $this->retrieve($sql);
    }
    
    public function searchByFieldId($field_id, $is_rank_alpha) {
        $field_id  = $this->da->escapeInt($field_id);
        $sql = "SELECT *
                FROM $this->table_name
                WHERE field_id = $field_id 
                ORDER BY ". ($is_rank_alpha ? 'label' : 'rank');
        return $this->retrieve($sql);
    }
    public function duplicate($from_value_id, $to_field_id) {
        $from_value_id  = $this->da->escapeInt($from_value_id);
        $to_field_id    = $this->da->escapeInt($to_field_id);
        $sql = "INSERT INTO $this->table_name (field_id, label, description, rank, is_hidden)
                SELECT $to_field_id, label, description, rank, is_hidden
                FROM $this->table_name
                WHERE id = $from_value_id";
        return $this->updateAndGetLastId($sql);
    }
    public function create($field_id, $label, $description, $rank, $is_hidden) {
        $field_id     = $this->da->escapeInt($field_id);
        $label        = $this->da->quoteSmart($label);
        $description  = $this->da->quoteSmart($description);
        $rank         = $this->da->escapeInt($this->prepareRanking(0, $field_id, $rank, 'id', 'field_id'));
        $is_hidden    = $this->da->escapeInt($is_hidden);
        
        $sql = "INSERT INTO $this->table_name (field_id, label, description, rank, is_hidden)
                VALUES ($field_id, $label, $description, $rank, $is_hidden)";
        return $this->updateAndGetLastId($sql);
    }
    
    
    public function save($id, $field_id, $label, $description, $rank, $is_hidden) {
        $id           = $this->da->escapeInt($id);
        $field_id     = $this->da->escapeInt($field_id);
        $label        = $this->da->quoteSmart($label);
        $description  = $this->da->quoteSmart($description);
        $rank         = $this->da->escapeInt($this->prepareRanking($id, $field_id, $rank, 'id', 'field_id'));
        $is_hidden    = $this->da->escapeInt($is_hidden);
        
        $sql = "UPDATE $this->table_name 
                SET label = $label, 
                    description = $description,
                    rank = $rank, 
                    is_hidden = $is_hidden
                WHERE field_id = $field_id
                  AND id = $id";
        return $this->update($sql);
    }
    
    public function delete($field_id, $id) {
        $id       = $this->da->escapeInt($id);
        $field_id = $this->da->escapeInt($field_id);
        $sql = "DELETE FROM $this->table_name 
                WHERE field_id = $field_id 
                  AND id = $id";
        return $this->update($sql);
    }
    
    public function searchChangesetValues($changeset_id, $field_id, $is_rank_alpha) {
        $changeset_id = $this->da->escapeInt($changeset_id);
        $field_id     = $this->da->escapeInt($field_id);
        $sql = "SELECT f.id
                FROM tracker_field_list_bind_static_value AS f 
                     INNER JOIN tracker_changeset_value_list AS l ON (l.bindvalue_id = f.id)
                     INNER JOIN tracker_changeset_value AS c
                     ON ( l.changeset_value_id = c.id
                      AND c.changeset_id = $changeset_id
                      AND c.field_id = $field_id
                     )
                ORDER BY f.". ($is_rank_alpha ? 'label' : 'rank');
        return $this->retrieve($sql);
    }
    
    public function canValueBeHidden($field_id, $value_id) {
        $field_id = $this->da->escapeInt($field_id);
        $value_id = $this->da->escapeInt($value_id);
        $sql = "SELECT null
                FROM $this->table_name AS v
                    INNER JOIN tracker_workflow_transition AS wt ON (wt.from_id = v.id AND v.id = $value_id)
                    INNER JOIN tracker_workflow AS w ON (w.workflow_id = wt.workflow_id AND v.field_id = w.field_id AND w.field_id = $field_id)
                UNION 
                SELECT null
                FROM $this->table_name AS v
                    INNER JOIN tracker_workflow_transition AS wt ON (wt.to_id = v.id AND v.id = $value_id)
                    INNER JOIN tracker_workflow AS w ON (w.workflow_id = wt.workflow_id AND v.field_id = w.field_id AND w.field_id = $field_id)
                UNION 
                SELECT null
                FROM $this->table_name AS v
                    INNER JOIN tracker_semantic_status AS s 
                    ON (s.open_value_id = v.id 
                        AND v.id = $value_id 
                        AND s.field_id = v.field_id 
                        AND s.field_id = $field_id)
                UNION 
                SELECT null
                FROM $this->table_name AS v
                    INNER JOIN tracker_rule AS tr
                    ON ( v.id = $value_id 
                        AND (tr.source_field_id = v.field_id OR tr.target_field_id = v.field_id)
                        AND ((tr.source_field_id = $field_id AND tr.source_value_id = $value_id) OR (tr.target_field_id = $field_id AND tr.target_value_id = $value_id)))
                
                ";
        return count($this->retrieve($sql)) == 0;
    }
    
    public function canValueBeDeleted($field_id, $value_id) {
        $field_id = $this->da->escapeInt($field_id);
        $value_id = $this->da->escapeInt($value_id);
        $sql = "SELECT null
                FROM $this->table_name AS v
                    INNER JOIN tracker_changeset_value_list AS cvl ON (v.id = cvl.bindvalue_id AND v.id = $value_id)
                    INNER JOIN tracker_changeset_value AS cv ON (cv.id = cvl.changeset_value_id AND cv.field_id = v.field_id AND cv.field_id = $field_id)
                UNION
                SELECT null
                FROM $this->table_name AS v
                    INNER JOIN tracker_changeset_value_openlist AS cvl ON (v.id = cvl.bindvalue_id AND v.id = $value_id)
                    INNER JOIN tracker_changeset_value AS cv ON (cv.id = cvl.changeset_value_id AND cv.field_id = v.field_id AND cv.field_id = $field_id)
                ";
        return $this->canValueBeHidden($field_id, $value_id) && count($this->retrieve($sql)) == 0;
    }
}
?>