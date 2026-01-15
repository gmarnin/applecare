#!/bin/bash

# Remove applecare script
/bin/rm -f "${MUNKIPATH}preflight.d/applecare"

# Remove applecare cache
/bin/rm -f "${CACHEPATH}applecare.plist"
