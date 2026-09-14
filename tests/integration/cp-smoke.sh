#!/bin/bash
# Loads every Glue control-panel screen as a logged-in admin, then exercises the write paths.
#
#     ddev exec bash /var/www/craft-glue/tests/integration/cp-smoke.sh
#
# A Twig template only breaks when it is rendered, and no amount of PHP linting catches that.
# A screen that renders is also not a screen that saves, so the POSTs below matter as much as the
# GETs: the merge form posts a shape the controller has to turn back into a plan, and every GET
# in this file stays green whether that works or not.

JAR=/tmp/glue-cp-jar.txt
rm -f "$JAR"

csrf() {
    curl -s -b "$JAR" -c "$JAR" "http://localhost/index.php?p=actions/users/session-info" \
        -H "Accept: application/json" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("csrfTokenValue",""))'
}

LOGIN=$(curl -s -b "$JAR" -c "$JAR" -X POST "http://localhost/index.php?p=actions/users/login" \
    -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest" \
    --data-urlencode "loginName=${GLUE_CP_USER:-admin}" \
    --data-urlencode "password=${GLUE_CP_PASS:-claudepassword}" \
    --data-urlencode "CRAFT_CSRF_TOKEN=$(csrf)")

echo "login: $(echo "$LOGIN" | head -c 80)"
echo

cd /var/www/html || exit 1

# Craft has no `plugin/switch-edition` command, so the edition is set through the plugin's own
# helper. Pro first, so every screen is reachable; Lite is checked at the end.
EDITION_BEFORE=$(php /var/www/craft-glue/tests/integration/edition.php)
php /var/www/craft-glue/tests/integration/edition.php pro >/dev/null

SEED=$(php /var/www/craft-glue/tests/integration/seed.php) || exit 1
A=$(echo "$SEED" | awk '{print $1}')
B=$(echo "$SEED" | awk '{print $2}')
SECTION=$(echo "$SEED" | awk '{print $3}')
TYPE=$(echo "$SEED" | awk '{print $4}')

echo "  seeded: a=$A b=$B section=$SECTION entryType=$TYPE"
echo

FAILED=0

visit() {
    CODE=$(curl -s -b "$JAR" -c "$JAR" -o /tmp/glue-page.html -w "%{http_code}" "http://localhost/admin/$1")
    TITLE=$(grep -oE "<title>[^<]*" /tmp/glue-page.html | head -1 | sed "s/<title>//")

    if [ "$CODE" = "200" ]; then
        echo "  ✓ $CODE  $1  — $TITLE"
    else
        FAILED=$((FAILED + 1))
        echo "  ✗ $CODE  $1  — $TITLE"
        grep -oiE "(exception|Unable to|Unknown|error)[^<]{0,180}" /tmp/glue-page.html | head -3
    fi
}

# Some screens render 200 while quietly swallowing a broken partial, so the ones that matter are
# also checked for a string only the working version produces.
contains() {
    if grep -q "$1" /tmp/glue-page.html; then
        echo "    ✓ found “$1”"
    else
        FAILED=$((FAILED + 1))
        echo "    ✗ missing “$1”"
    fi
}

for SCREEN in "glue" "glue/history" "glue/duplicates" "settings/plugins/glue"; do
    visit "$SCREEN"
done

visit "glue/merge?a=$A&b=$B"
contains "glue-fields"
contains "glueSmokeBlocks"

visit "glue/merge?a=$A&b=$B&target=a"
visit "glue/merge?a=$A&b=$B&target=b"
visit "glue/merge?a=$A&b=$B&entryType=$TYPE"

# Refusals should be refusals, not stack traces.
CODE=$(curl -s -b "$JAR" -c "$JAR" -o /tmp/glue-page.html -w "%{http_code}" "http://localhost/admin/glue/merge?a=$A")
if [ "$CODE" = "400" ]; then
    echo "  ✓ $CODE  glue/merge with one entry is refused"
else
    FAILED=$((FAILED + 1))
    echo "  ✗ $CODE  glue/merge with one entry should be a 400"
fi

echo
echo "  write paths"

post() {
    LABEL=$1; ACTION=$2; shift 2
    ARGS=()
    for PAIR in "$@"; do ARGS+=(--data-urlencode "$PAIR"); done

    CODE=$(curl -s -b "$JAR" -c "$JAR" -o /tmp/glue-post.html -w "%{http_code}" \
        -X POST "http://localhost/admin/actions/$ACTION" \
        -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest" \
        --data-urlencode "CRAFT_CSRF_TOKEN=$(csrf)" "${ARGS[@]}")

    if [ "$CODE" = "200" ] || [ "$CODE" = "302" ]; then
        echo "    ✓ $CODE  $LABEL"
    else
        FAILED=$((FAILED + 1))
        echo "    ✗ $CODE  $LABEL"
        head -c 400 /tmp/glue-post.html; echo
    fi
}

post "preview the merged values" "glue/merge/preview" \
    "a=$A" "b=$B" "siteId=1" "target=new" "sectionId=$SECTION" "entryTypeId=$TYPE" \
    "choices[glueSmokeBody]=combine" "choices[glueSmokeTopics]=combine" "choices[glueSmokeBlocks]=combine"

# The preview must actually contain the combined body, not merely return 200.
if grep -q "words" /tmp/glue-post.html; then
    echo "    ✓ the preview described the combined value"
else
    FAILED=$((FAILED + 1))
    echo "    ✗ the preview came back without a description"
    head -c 300 /tmp/glue-post.html; echo
fi

post "save a preset" "glue/merge/save-preset" \
    "name=Smoke Preset" "a=$A" "b=$B" "siteId=1" "target=new" "sectionId=$SECTION" "entryTypeId=$TYPE" \
    "choices[glueSmokeBody]=combine"

PRESET=$(python3 -c 'import json,sys; print(json.load(open("/tmp/glue-post.html")).get("handle",""))' 2>/dev/null)
echo "    preset handle: ${PRESET:-<none>}"

if [ -n "$PRESET" ]; then
    visit "glue/merge?a=$A&b=$B&preset=$PRESET"
    post "delete the preset" "glue/merge/delete-preset" "handle=$PRESET"
fi

post "save the plugin settings" "plugins/save-plugin-settings" \
    "pluginHandle=glue" \
    "settings[defaultTarget]=new" \
    "settings[defaultStrategy]=nonEmpty" \
    "settings[defaultDisposition]=leave" \
    $'settings[textSeparator]=\r\n\r\n' \
    $'settings[richTextSeparator]=\r\n' \
    "settings[dedupeRows]=1" \
    "settings[rewireRelations]=" \
    "settings[rewireThreshold]=25" \
    "settings[allSites]=" \
    "settings[logMerges]=1" \
    "settings[historyLimit]=500"

# The separators have to have survived the round trip through the settings form — posted as
# CRLF, which is what a browser sends from a textarea, and expected back as LF. A model that
# stored the CRLF verbatim would put a stray carriage return in the middle of every combined
# field, and every other check in this file would stay green while it did.
SEPARATOR=$(php -r '
    require "/var/www/html/vendor/autoload.php";
    define("CRAFT_BASE_PATH", "/var/www/html");
    define("CRAFT_VENDOR_PATH", "/var/www/html/vendor");
    $app = require "/var/www/html/vendor/craftcms/cms/bootstrap/console.php";
    echo json_encode(Craft::$app->getPlugins()->getPlugin("glue")->getSettings()->textSeparator);
' 2>/dev/null)

if [ "$SEPARATOR" = '"\n\n"' ]; then
    echo "    ✓ the plain-text separator survived the form as a blank line"
else
    FAILED=$((FAILED + 1))
    echo "    ✗ the plain-text separator came back as $SEPARATOR"
fi

post "run the merge" "glue/merge/save" \
    "a=$A" "b=$B" "siteId=1" "target=new" "sectionId=$SECTION" "entryTypeId=$TYPE" \
    "choices[glueSmokeBody]=combine" "choices[glueSmokeTopics]=combine" "choices[glueSmokeBlocks]=combine" \
    "titleFrom=custom" "title=Smoke Merged" "slugFrom=auto" "authorFrom=current" \
    "postDateFrom=earliest" "statusFrom=a" "disposition=leave" \
    "redirect=$(php -r 'echo urlencode("glue/history");')"

# The merge has to have produced an entry with both bodies in it.
MERGED=$(php -r '
    require "/var/www/html/vendor/autoload.php";
    define("CRAFT_BASE_PATH", "/var/www/html");
    define("CRAFT_VENDOR_PATH", "/var/www/html/vendor");
    $app = require "/var/www/html/vendor/craftcms/cms/bootstrap/console.php";
    $e = craft\elements\Entry::find()->title("Smoke Merged")->status(null)->one();
    if (!$e) { echo "none"; exit; }
    $body = (string)$e->getFieldValue("glueSmokeBody");
    $blocks = count($e->getFieldValue("glueSmokeBlocks")->all());
    $topics = $e->getFieldValue("glueSmokeTopics")->count();
    printf("%d|%s|%d|%d", $e->id, str_contains($body, "Alpha body.\nSecond line.\n\nBeta body.") ? "both" : "partial", $blocks, $topics);
' 2>/dev/null)

echo "    merged entry: $MERGED"

case "$MERGED" in
    *"|both|2|2")
        echo "    ✓ the merged entry has both bodies, both blocks and both topics"
        ;;
    *)
        FAILED=$((FAILED + 1))
        echo "    ✗ the merged entry is not what the plan asked for"
        ;;
esac

visit "glue/history"
contains "Smoke Merged"

# The template hook on the entry's own edit screen — the only place a merged entry can tell you
# what it is, and a hook that throws takes the entry editor down with it rather than just Glue.
MERGED_ID=$(echo "$MERGED" | cut -d'|' -f1)
visit "entries/glueSmokeSection/$MERGED_ID"
contains "Merged from"

# ---------------------------------------------------------------------------------------------
# The edition boundary, from the outside. A Pro screen that 403s for a Lite licence is the whole
# difference between an edition and a suggestion, and it is only ever exercised over HTTP.
# ---------------------------------------------------------------------------------------------

echo
echo "  the Lite boundary"

php /var/www/craft-glue/tests/integration/edition.php lite >/dev/null

refuses() {
    CODE=$(curl -s -b "$JAR" -c "$JAR" -o /tmp/glue-page.html -w "%{http_code}" "http://localhost/admin/$1")

    if [ "$CODE" = "403" ]; then
        echo "    ✓ $CODE  $1 is refused on Lite"
    else
        FAILED=$((FAILED + 1))
        echo "    ✗ $CODE  $1 should be refused on Lite"
    fi
}

refuses "glue/duplicates"

CODE=$(curl -s -b "$JAR" -c "$JAR" -o /tmp/glue-page.html -w "%{http_code}" "http://localhost/admin/glue/merge?a=$A&b=$B")
if [ "$CODE" = "200" ] && ! grep -q 'name="rewire"' /tmp/glue-page.html; then
    echo "    ✓ $CODE  the merge screen still works on Lite, without the rewire switch"
else
    FAILED=$((FAILED + 1))
    echo "    ✗ $CODE  the Lite merge screen is wrong"
fi

php /var/www/craft-glue/tests/integration/edition.php "${EDITION_BEFORE:-lite}" >/dev/null
php /var/www/craft-glue/tests/integration/seed.php --clean >/dev/null

echo
if [ "$FAILED" = "0" ]; then
    echo "All screens loaded and every write path worked."
else
    echo "$FAILED check(s) failed."
fi

exit $FAILED
