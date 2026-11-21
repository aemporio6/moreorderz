#!/bin/bash
# short test report: one block per failure (name, message, test line)
cd /home/claude/moreorderz && php artisan test "$@" 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | awk '/FAILED/{print; c=7; next} c>0 && !/^\s*$/ && !/vendor|▕ +(\$exception|\$this->|\$query|\$e,|\? Unique|: Query)/{print substr($0,1,330); c--} /Tests:/{print}' | head -${LINES_MAX:-60}
