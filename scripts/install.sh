#!/bin/bash

# AppleCare controller
CTL="${BASEURL}index.php?/module/applecare/"

# Get the scripts in the proper directories
"${CURL[@]}" "${CTL}get_script/applecare.sh" -o "${MUNKIPATH}preflight.d/applecare"

# Check exit status of curl
if [ $? = 0 ]; then
	# Make executable
	/bin/chmod a+x "${MUNKIPATH}preflight.d/applecare"

	# Set preference to include this file in the preflight check
	setreportpref "applecare" "${CACHEPATH}applecare.plist"

else
	echo "Failed to download all required components!"
	/bin/rm -f "${MUNKIPATH}preflight.d/applecare"

	# Signal that we had an error
	ERR=1
fi
