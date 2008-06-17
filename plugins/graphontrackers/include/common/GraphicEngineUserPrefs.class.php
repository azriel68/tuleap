<?php
/*
 * Copyright (c) STMicroelectronics, 2006. All Rights Reserved.
 *
 * Originally written by Mahmoud MAALEJ, 2006. STMicroelectronics.
 *
 * This file is a part of CodeX.
 *
 * CodeX is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * CodeX is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with CodeX; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

require_once('www/include/pre.php');
require_once('common/tracker/ArtifactField.class.php');
require_once('common/tracker/ArtifactReport.class.php');


class graphicEngineUserPrefs {

    var $atid;
    var $prefs;
	var $advsrch;
	var $morder;
	var $report_id;

    /**
	 * Class constructor
	 *
	 * 	@param atid:artifact type id
	 */

	function graphicEngineUserPrefs($atid) {
	    $this->atid = $atid;
	}

    /**
	 * function to set user preferences
	 *
	 * 	@return null
	 */


    function fetchPrefs(){

    	$prefs     = array();
        $advsrch   = 0;
        $morder    = "";
        $report_id = 100;

        //if (user_isloggedin()) {
    	    $custom_pref = user_get_preference('artifact_brow_cust'.$this->atid);
	        if ($custom_pref) {
	            $pref_arr = explode('&',substr($custom_pref,1));
	            while (list(,$expr) = each($pref_arr)) {
    	            // Extract left and right parts of the assignment
		            // and remove the '[]' array symbol from the left part
		            list($field,$value_id) = explode('=',$expr);
		            $field = str_replace('[]','',$field);
		            if ($field == 'advsrch')
    		            $advsrch = ($value_id ? 1 : 0);
		            else if ($field == 'msort')
  		                $msort = ($value_id ? 1 : 0);
		            else if ($field == 'chunksz')
  		                $chunksz = $value_id;
		            else if ($field == 'report_id')
		                $report_id = $value_id;
		            else
		                $prefs[$field][] = urldecode($value_id);
		            //echo '<br>DBG restoring prefs : $prefs['.$field.'] []='.$value_id;
	            }
	        }
            $morder = user_get_preference('artifact_browse_order'.$this->atid);
        //}
        $this->prefs     = $prefs;
	    $this->advsrch   = $advsrch;
	    $this->morder    = $morder;
	    $this->report_id = $report_id;
    }

    /**
	 * function to get artifacts in specified preference order
	 *
	 * 	@return null
	 *
	 */


    function getArtifactsInOrder() {
        $ar  = new ArtifactReport($this->report_id,$this->atid);
        $ar->getResultQueryElements($this->prefs,$this->morder,$this->advsrch,$aids = false,&$select,&$from,&$where,&$order_by);
        if ($order_by == "") {
            $sql = "SELECT DISTINCT art.artifact_id FROM (SELECT a.artifact_id $from $where $order_by) AS art";
        } else {
        	$sql = "SELECT DISTINCT art.artifact_id FROM (SELECT a.artifact_id $from $where $order_by,a.artifact_id ASC) AS art";
        }
        return $ar->_ExecuteQueryForSelectReportItems($sql);
    }


}

?>