#!/bin/bash
# usage: g.sh 'regex' ...  -> count of files matching in code
cd /home/claude/moreorderz
for p in "$@"; do printf "%-48s %s\n" "$p" "$(grep -rliE -- "$p" app routes public/app/app.js tests database/migrations resources config tools 2>/dev/null | wc -l) $(grep -rliE -- "$p" app routes public/app/app.js tests database/migrations 2>/dev/null | head -3 | xargs -n1 basename 2>/dev/null | tr '\n' ' ')"; done
