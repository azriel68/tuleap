#!/bin/sh
#
# CodeX: Breaking Down the Barriers to Source Code Sharing inside Xerox
# Copyright (c) Xerox Corporation, CodeX, 2001-2004. All Rights Reserved
# This file is licensed under the CodeX Component Software License
# http://codex.xerox.com
#
# Purpose:
#    Automatically copy CodeX JRI into CodeX plugin
#
CP='/bin/cp'
MV='/bin/mv'
RM='/bin/rm'
RMDIR='/bin/rmdir'
TAR='/bin/tar'

CODEXJRI_SOURCE_REPOSITORY="https://partners.xrce.xerox.com/svnroot/codexjri/dev/trunk"
CODEXJRI_JAR_DIRECTORY="/home/mnazaria/workspace_Deployment/EclipsePluginUpdateSite/plugins"

CODEX_TARGET_DIR="/home/mnazaria/CodeX/dev_server/httpd"

#
# CodeXJRI sources
#
svn export --force $CODEXJRI_SOURCE_REPOSITORY/ $CODEX_TARGET_DIR/plugins/codexjri/src/.

#
# CodeXJRI documentation (javadoc)
#
svn export --force $CODEXJRI_SOURCE_REPOSITORY/doc/ $CODEX_TARGET_DIR/plugins/codexjri/www/documentation/.


#
# CodeXJRI jar
#
$CP $CODEXJRI_JAR_DIRECTORY/com.xerox.xrce.codex.jri*.jar $CODEX_TARGET_DIR/plugins/codexjri/www/jars/.


#
# make an archive of the sources, and remove the sources!
#
cd $CODEX_TARGET_DIR/plugins/codexjri/src/ ; $TAR -cf codexjri_src.tar.gz -v -z -h src lib WSDLClassesGenerator\ JRI.launch doc plugin.xml --exclude '.svn' --remove-files
$RMDIR lib
$RM wsdl/CodeXJRI.wsdl
$RMDIR wsdl
$RM -r src
$RM -r doc
$RM build.properties javadoc.xml CodexCodeFormatter.xml .project .classpath
cd -

echo "################################################################################"
echo "  Don't forget to:"
echo "  - update the symbolic link com.xerox.xrce.codex.jri.jar to point the last jar"
echo "  - commit the modifications !  "
echo "################################################################################"