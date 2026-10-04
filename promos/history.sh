#!/bin/bash
# Runs three real merges as the admin, so the history screen has something to show.
#
#     ddev exec bash /var/www/craft-glue/promos/history.sh '<fixture.php JSON>'
#
# Goes through glue/merge/save over HTTP, exactly as the merge screen does, so each row records a
# user rather than the console.
JSON=$1
JAR=/tmp/glue-promo-jar.txt
rm -f "$JAR"
j() { echo "$JSON" | python3 -c "import sys,json; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }

csrf() {
    curl -s -b "$JAR" -c "$JAR" "http://localhost/index.php?p=actions/users/session-info" \
        -H "Accept: application/json" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("csrfTokenValue",""))'
}
curl -s -b "$JAR" -c "$JAR" -X POST "http://localhost/index.php?p=actions/users/login" \
    -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest" \
    --data-urlencode "loginName=admin" --data-urlencode "password=${GLUE_CP_PASS:-claudepassword}" \
    --data-urlencode "CRAFT_CSRF_TOKEN=$(csrf)" >/dev/null

SECTION=$(j "d['section']"); TYPE=$(j "d['type']")

merge() {
    A=$1; B=$2; shift 2
    ARGS=()
    for P in "$@"; do ARGS+=(--data-urlencode "$P"); done
    CODE=$(curl -s -b "$JAR" -c "$JAR" -o /tmp/glue-promo-post.html -w "%{http_code}" \
        -X POST "http://localhost/admin/actions/glue/merge/save" \
        -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest" \
        --data-urlencode "CRAFT_CSRF_TOKEN=$(csrf)" \
        --data-urlencode "a=$A" --data-urlencode "b=$B" --data-urlencode "siteId=1" \
        --data-urlencode "sectionId=$SECTION" --data-urlencode "entryTypeId=$TYPE" \
        --data-urlencode "slugFrom=a" --data-urlencode "authorFrom=a" --data-urlencode "postDateFrom=earliest" \
        --data-urlencode "statusFrom=a" "${ARGS[@]}")
    echo "$CODE $(head -c 160 /tmp/glue-promo-post.html)"
}

merge "$(j "d['history'][0][0]")" "$(j "d['history'][0][1]")" "target=a" "disposition=trash" "titleFrom=a"
merge "$(j "d['history'][1][0]")" "$(j "d['history'][1][1]")" "target=new" "disposition=disable" "titleFrom=b" "choices[releaseSummary]=combine"
merge "$(j "d['history'][2][0]")" "$(j "d['history'][2][1]")" "target=b" "disposition=trash" "titleFrom=b"
