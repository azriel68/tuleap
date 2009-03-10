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
 * along with Codendi; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * 
 */

require_once('common/backend/Backend.class.php');


class BackendCVS extends Backend {

    var $CVSRootListNeedUpdate;
    var $UseCVSNT;

    /**
     * Constructor
     */
    protected function BackendCVS() {
        Backend::Backend();
    }


    /**
     * Return true if server uses CVS NT, or false if it uses GNU CVS
     */
    function useCVSNT() {
        if (isset($UseCVSNT)) return $UseCVSNT;
        if (is_file("/usr/bin/cvsnt")) {
            $UseCVSNT=true;
        } else {$UseCVSNT=false;}
        return $UseCVSNT;
    }


    /**
     * Create project CVS repository
     * If the directory already exists, nothing is done.
     * @return true if repo is successfully created, false otherwise
     */
    function createProjectCVS($group_id) {
        $project=$this->_getProjectManager()->getProject($group_id);
        if (!$project) return false;

        $unix_group_name=$project->getUnixName(false); // May contain upper-case letters
        $cvs_dir=$GLOBALS['cvs_prefix']."/".$unix_group_name;
        if (!is_dir($cvs_dir)) {
            // Let's create a CVS repository for this group
            if (!mkdir($cvs_dir)) {
                $this->log("Can't create project CVS dir: $cvs_dir");
                return false;
            }

            if ($this->useCVSNT()) {
                // Tell cvsnt not to update /etc/cvsnt/PServer: this is done later by this the script.
                system($GLOBALS['cvs_cmd']." -d$cvs_dir init -n");
            } else {
                system($GLOBALS['cvs_cmd']." -d$cvs_dir init");
            }

            // Turn off pserver writers, on anonymous readers
            // See CVS writers update below. Just create an
            // empty writers file so that we can set up the appropriate
            // ownership right below. We will put names in writers
            // later in the script
            system("echo \"\" > $cvs_dir/CVSROOT/writers");
           
            if (!$this->useCVSNT()) {
                // But to allow checkout/update to registered users we
                // need to setup a world writable directory for CVS lock files
                $lockdir=$GLOBALS['cvslock_prefix']."/$unix_group_name";
                $filename= "$cvs_dir/CVSROOT/config";
                system("echo  >> $filename");
                system("echo '# !!! CodeX Specific !!! DO NOT REMOVE' >> $filename");
                system("echo '# Put all CVS lock files in a single directory world writable' >> $filename");
                system("echo '# directory so that any CodeX registered user can checkout/update' >> $filename");
                system("echo '# without having write permission on the entire cvs tree.' >> $filename");
                system("echo 'LockDir=$lockdir' >> $filename");
                // commit changes to config file (directly with RCS)
                $this->_RcsCommit($filename);
            }

            // setup loginfo to make group ownership every commit
            // commit changes to config file (directly with RCS)
            $filename= "$cvs_dir/CVSROOT/loginfo";
            if ($this->useCVSNT()) {
                // use DEFAULT because there is an issue with multiple 'ALL' lines with cvsnt.
                system("echo \"DEFAULT chgrp -f -R  $unix_group_name $cvs_dir\" > $filename");
            } else {
                system("echo \"ALL (cat;chgrp -R $unix_group_name $cvs_dir)>/dev/null 2>&1\" > $filename");
            }
            $this->_RcsCommit($filename);

            // put an empty line in in the valid tag cache (means no tag yet)
            // (this file is not under version control so don't check it in)
            system("echo \"\" > $cvs_dir/CVSROOT/val-tags");
            chmod("$cvs_dir/CVSROOT/val-tags",0664);

            // set group ownership, http user
            $this->recurseChownChgrp($cvs_dir,$GLOBALS['sys_http_user'],$unix_group_name);
            system("chmod g+rw $cvs_dir");
        }

        // Lockdir does not exist? (Re)create it.
        if (!$this->useCVSNT()) {
            $lockdir=$GLOBALS['cvslock_prefix']."/$unix_group_name";
            if (! is_dir($lockdir)) {
                if (!mkdir("$lockdir",0777)) {
                    $this->log("Can't create project CVS lock dir: $lockdir");
                    return false;
                }
                chmod("$lockdir",0777); // overwrite umask value
            }
        }

        // history was deleted (or not created)? Recreate it.
        if ($this->useCVSNT()) {
            // Create history file (not created by default by cvsnt)
            system("touch $cvs_dir/CVSROOT/history");
            // Must be writable
            chmod("$cvs_dir/CVSROOT/history",0666);
            $this->recurseChownChgrp($cvs_dir."/CVSROOT",$GLOBALS['sys_http_user'],$unix_group_name);
        }

/* NG: still TODO
 	    # LJ if the CVS repo has just been created or the user list
	    # in the group has been modified then update the CVS
	    # writer file

	    if ($group_modified) {
		# On CodeX writers go through pserver as well so put
		# group members in writers file. Do not write anything
		# in the CVS passwd file. The pserver protocol will fallback
		# on /etc/passwd for user authentication
		my $cvswriters_file = "$cvs_dir/CVSROOT/writers";
		open(WRITERS,"+>$cvswriters_file")
		    or croak "Can't open CVS writers file $cvswriters_file: $!";  
		print WRITERS join("\n",split(",",$userlist)),"\n";
		close(WRITERS);
	    }
*/
        if ($project->isCVSTracked()) {
            // hook for commit tracking in cvs loginfo file
            $filename = "$cvs_dir/CVSROOT/loginfo";
            $file_array=file($filename);
            if (!in_array($this->block_marker_start,$file_array)) {
                if ($this->useCVSNT()) {
                        $command = "ALL ".$GLOBALS['codex_bin_prefix']."/log_accum -T $unix_group_name -C $unix_group_name -s %{sVv}";
                } else {
                        $command = "ALL (".$GLOBALS['codex_bin_prefix']."/log_accum -T $unix_group_name -C $unix_group_name -s %{sVv})>/dev/null 2>&1";
                }
                $this->addBlock($filename,$command);
                $this->_RcsCommit($filename);
                $this->recurseChownChgrp($cvs_dir."/CVSROOT",$GLOBALS['sys_http_user'],$unix_group_name);
            }

            
            // hook for commit tracking in cvs commitinfo file
            $filename = "$cvs_dir/CVSROOT/commitinfo";
            $file_array=file($filename);
            if (!in_array($this->block_marker_start,$file_array)) {
                $this->addBlock($filename,"ALL ".$GLOBALS['codex_bin_prefix']."/commit_prep -T $unix_group_name -r");
                $this->_RcsCommit($filename);
                $this->recurseChownChgrp($cvs_dir."/CVSROOT",$GLOBALS['sys_http_user'],$unix_group_name);
            }
        }

        // Add notify command if cvs_watch_mode is on
        if ($project->getCVSWatchMode()){
            $filename = "$cvs_dir/CVSROOT/notify";
            $file_array=file($filename);
            if (!in_array($this->block_marker_start,$file_array)) {
                $this->addBlock($filename,'ALL mail %s -s "CVS notification"');
                $this->_RcsCommit($filename);

                // Apply cvs watch on only if cvs_watch_mode changed to on 
                $this->CVSWatch($cvs_dir,$unix_group_name,1);
                $this->recurseChownChgrp($cvs_dir,$GLOBALS['sys_http_user'],$unix_group_name);
                system("chmod g+rw $cvs_dir");
            }
        }
      
        // Remove notify command if cvs_watch_mode is off.
        if (! $project->getCVSWatchMode()) {
            $filename = "$cvs_dir/CVSROOT/notify";
            $file_array=file($filename);
            if (in_array($this->block_marker_start,$file_array)) {
                // Switch to cvs watch off
                $this->removeBlock($filename);
                $this->_RcsCommit($filename);
                $this->recurseChownChgrp($cvs_dir."/CVSROOT",$GLOBALS['sys_http_user'],$unix_group_name);
                $this->CVSWatch($cvs_dir,$unix_group_name,0);
            }
        }

        return true;
    }

    function _CVSWatch($cvs_dir, $unix_group_name, $watch_mode) {
        $sandbox_dir =  $GLOBALS['tmp_dir']."/".$unix_group_name.".cvs_watch_sandbox";
        if (is_dir($sandbox_dir)) {
            return false;
        } else {
            mkdir("$sandbox_dir",0700);
            chmod("$sandbox_dir",0700); // overwrite umask value
        }
        if ($watch_mode == 1) {
            system("cd $sandbox_dir;cvs -d$cvs_dir co . 2>/dev/null 1>&2;cvs -d$cvs_dir watch on 2>/dev/null 1>&2;");
        } else {
            system("cd $sandbox_dir;cvs -d$cvs_dir co . 2>/dev/null 1>&2;cvs -d$cvs_dir watch off 2>/dev/null 1>&2;");
        }
        system("rm -rf $sandbox_dir;");
        return true;
    }

    function _RcsCommit($file) {
        system("/usr/bin/rcs -q -l $file; ci -q -m\"Codendi modification\" $file; co -q $file");
    }

    function archiveProjectCVS($group_id) {
        $project=$this->_getProjectManager()->getProject($group_id);
        if (!$project) return false;
        $mydir=$GLOBALS['cvs_prefix']."/".$project->getUnixName(false);
        $backupfile=$GLOBALS['tmp_dir']."/".$project->getUnixName(false)."-cvs.tgz";

        if (is_dir($mydir)) {
            system("cd ".$GLOBALS['cvs_prefix']."; tar cfz $backupfile ".$project->getUnixName(false));
            chmod($backupfile,0600);
            $this->recurseDeleteInDir($mydir);
            rmdir($mydir);
            return true;
       } else return false;
     }


    function setNeedUpdateCVSRootList() {
        $this->CVSRootListNeedUpdate=true;
    }

    function CVSRootListneedUpdate() {
        return $this->CVSRootListNeedUpdate;
    }

    function CVSRootListUpdate() {
        // TODO
    }

}

?>