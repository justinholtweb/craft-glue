/* global Craft, $ */

/**
 * The merge screen's behaviour.
 *
 * Every one of these is a convenience over a form that already works: the radios post, the
 * selects post, and a merge submitted with JavaScript off does exactly the same thing minus the
 * live result column. That is deliberate — the result column is a preview of a decision, and a
 * preview that is the only way to make the decision is a dependency, not a convenience.
 */
(function () {
  'use strict';

  var form = document.getElementById('main-form');
  var table = document.getElementById('glue-fields');

  if (!form) {
    return;
  }

  // ------------------------------------------------------------------ live result column

  var previewTimer = null;
  var previewSeq = 0;

  function schedulePreview() {
    window.clearTimeout(previewTimer);
    previewTimer = window.setTimeout(runPreview, 250);
  }

  function runPreview() {
    if (!table) {
      return;
    }

    // Every response carries the sequence number of the request that produced it. Without this,
    // a slow resolve of a Matrix-heavy entry can land after a later, faster one and paint a
    // result column that matches neither the choices on screen nor anything that will be saved.
    var seq = ++previewSeq;

    table.classList.add('glue-loading');

    Craft.sendActionRequest('POST', 'glue/merge/preview', {data: new FormData(form)})
      .then(function (response) {
        if (seq !== previewSeq) {
          return;
        }

        var data = response.data || {};

        if (data.error) {
          setAllResults(data.error);
          return;
        }

        Object.keys(data.previews || {}).forEach(function (handle) {
          var cell = table.querySelector('[data-glue-result="' + cssEscape(handle) + '"]');

          if (cell) {
            cell.innerHTML = data.previews[handle];
          }
        });
      })
      .catch(function () {
        if (seq === previewSeq) {
          setAllResults(Craft.t('glue', 'Could not work out the result.'));
        }
      })
      .then(function () {
        if (seq === previewSeq) {
          table.classList.remove('glue-loading');
        }
      });
  }

  function setAllResults(message) {
    var cells = table.querySelectorAll('[data-glue-result]');

    for (var i = 0; i < cells.length; i++) {
      cells[i].textContent = message;
    }
  }

  function cssEscape(value) {
    if (window.CSS && window.CSS.escape) {
      return window.CSS.escape(value);
    }

    return String(value).replace(/["\\]/g, '\\$&');
  }

  // ------------------------------------------------------------------ choosing

  if (table) {
    table.addEventListener('change', function (event) {
      if (event.target && event.target.type === 'radio') {
        markRow(event.target);
        schedulePreview();
      }
    });

    // Clicking anywhere in a value cell picks that side. The radios are small and the values are
    // large, and the value is what the editor is actually looking at when they decide.
    table.addEventListener('click', function (event) {
      var cell = event.target.closest ? event.target.closest('[data-glue-pick]') : null;

      if (!cell) {
        return;
      }

      // Not when they clicked a chip — that is a link to the related element, and hijacking it
      // would make the chips useless.
      if (event.target.closest('a')) {
        return;
      }

      var parts = cell.getAttribute('data-glue-pick').split(':');
      var radio = table.querySelector(
        'input[name="choices[' + parts[0] + ']"][value="' + parts[1] + '"]'
      );

      if (radio && !radio.disabled) {
        radio.checked = true;
        markRow(radio);
        schedulePreview();
      }
    });

    var bulk = document.querySelectorAll('[data-glue-all]');

    for (var i = 0; i < bulk.length; i++) {
      bulk[i].addEventListener('click', function (event) {
        applyToAll(event.currentTarget.getAttribute('data-glue-all'));
      });
    }

    var toggle = document.getElementById('glue-differences-only');

    if (toggle) {
      toggle.addEventListener('click', function () {
        var on = toggle.getAttribute('aria-pressed') !== 'true';
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        table.classList.toggle('glue-differences-only', on);
      });
    }

    markAllRows();
    runPreview();
  }

  /**
   * Sets every row that can take a strategy to it.
   *
   * Rows where the strategy is not on offer — a field only one side has, a field that cannot be
   * combined — are left exactly as they were rather than forced to something else. "All both"
   * meaning "both where possible, and whatever you had chosen everywhere else" is the only
   * reading that does not quietly undo work.
   */
  function applyToAll(strategy) {
    var rows = table.querySelectorAll('[data-glue-row]');

    for (var i = 0; i < rows.length; i++) {
      var handle = rows[i].getAttribute('data-glue-row');
      var radio;

      if (strategy === 'reset') {
        radio = rows[i].querySelector('input[type="radio"][data-glue-suggested="1"]');
      } else {
        radio = rows[i].querySelector(
          'input[name="choices[' + handle + ']"][value="' + strategy + '"]'
        );
      }

      if (radio && !radio.disabled) {
        radio.checked = true;
        markRow(radio);
      }
    }

    schedulePreview();
  }

  function markRow(radio) {
    var row = radio.closest('tr');

    if (!row) {
      return;
    }

    row.setAttribute('data-glue-chosen', radio.value);
  }

  function markAllRows() {
    var checked = table.querySelectorAll('input[type="radio"]:checked');

    for (var i = 0; i < checked.length; i++) {
      markRow(checked[i]);
    }
  }

  // ------------------------------------------------------------------ reloading

  // Changing the target or the entry type changes which fields exist, so the screen is rebuilt
  // server-side rather than guessed at in the browser.
  var reloaders = document.querySelectorAll('[data-glue-reload]');

  for (var r = 0; r < reloaders.length; r++) {
    reloaders[r].addEventListener('change', function (event) {
      var params = new URLSearchParams(window.location.search);
      var which = event.currentTarget.getAttribute('data-glue-reload');
      var value = event.currentTarget.value;

      if (which === 'target') {
        params.set('target', value);
        params.delete('entryType');
      } else if (which === 'section') {
        params.set('section', value);
        params.delete('entryType');
      } else if (which === 'entryType') {
        params.set('entryType', value);
      }

      window.location.search = params.toString();
    });
  }

  // ------------------------------------------------------------------ presets

  var savePreset = document.getElementById('glue-save-preset');

  if (savePreset) {
    savePreset.addEventListener('click', function () {
      var input = document.getElementById('glue-preset-name');
      var name = input ? input.value.trim() : '';

      if (!name) {
        Craft.cp.displayError(Craft.t('glue', 'Give the preset a name.'));
        return;
      }

      var data = new FormData(form);
      data.append('name', name);

      savePreset.classList.add('loading');

      Craft.sendActionRequest('POST', 'glue/merge/save-preset', {data: data})
        .then(function (response) {
          if (response.data && response.data.error) {
            Craft.cp.displayError(response.data.error);
            return;
          }

          Craft.cp.displayNotice(Craft.t('glue', 'Preset saved.'));
        })
        .catch(function () {
          Craft.cp.displayError(Craft.t('glue', 'The preset could not be saved.'));
        })
        .then(function () {
          savePreset.classList.remove('loading');
        });
    });
  }
})();
