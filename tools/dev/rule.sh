#!/bin/bash
# usage: rule.sh C 15   (module letter or FIN etc)
grep -h "^R-$1-$2 ·" /home/claude/rebuild/projdocs/RULES_*.md | cut -c1-${3:-1500}
