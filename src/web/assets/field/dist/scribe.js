/* global Craft, Garnish, jQuery */
(function ($) {
  'use strict';

  // Repo picker + heading range for the Scribe field. The repo <select> is
  // selectized by Craft's forms.selectize macro; here we react to changes and
  // repopulate the Start From / End Before menus from the source's headings.
  Craft.ScribeField = Garnish.Base.extend({
    $url: null,
    $startFrom: null,
    $endBefore: null,
    $range: null,
    settings: null,
    headings: null,

    init: function (id, settings) {
      this.settings = settings;
      this.headings = settings.headings || [];
      this.$url = $('#' + id + '-url');
      this.$startFrom = $('#' + id + '-startFrom');
      this.$endBefore = $('#' + id + '-endBefore');
      this.$range = this.$url.closest('.scribe-field').find('[data-scribe-range]');

      this.addListener(this.$url, 'change', 'onChange');
      this.addListener(this.$startFrom, 'change', 'refreshEndBefore');
      this.hookRepoClear();

      // Render the heading menus (indented) from the server-provided headings,
      // preserving the saved selections.
      if (this.headings.length) {
        this.populate(this.headings);
      }
    },

    // Let emptying the repo box clear the field. Craft's select_on_focus plugin
    // restores the value on blur (native-select behaviour), so we track when the
    // box is emptied and force-clear on the next frame, after that restore runs.
    // (Selectize is set up by Craft's macro just after this — retry until ready.)
    hookRepoClear: function () {
      var self = this;
      var el = this.$url[0];
      if (!el.selectize) {
        Garnish.requestAnimationFrame(function () {
          self.hookRepoClear();
        });
        return;
      }
      var selectize = el.selectize;
      var $input = selectize.$control_input;
      var emptied = false;

      this.addListener($input, 'focus', function () {
        emptied = false;
      });
      this.addListener($input, 'input', function () {
        emptied = $input.val() === '';
      });
      this.addListener($input, 'blur', function () {
        if (emptied) {
          Garnish.requestAnimationFrame(function () {
            selectize.clear();
            selectize.setTextboxValue('');
            self.onChange(); // hide the heading range now the repo's empty
          });
        }
      });
    },

    onChange: function () {
      var url = this.$url.val();
      if (!url) {
        this.$range.addClass('hidden');
        return;
      }
      this.loadHeadings(url);
    },

    loadHeadings: function (url) {
      var self = this;
      Craft.sendActionRequest('POST', this.settings.headingsAction, {
        data: { url: url },
      })
        .then(function (response) {
          self.populate((response.data && response.data.headings) || []);
        })
        .catch(function () {
          self.populate([]);
        })
        .finally(function () {
          self.$range.removeClass('hidden');
        });
    },

    populate: function (headings) {
      this.headings = headings || [];
      // Start From offers every heading; keep the current choice if still valid.
      var current = this.$startFrom.val();
      var keep = this.headings.some(function (h) { return h.value === current; });
      this.$startFrom.html(this.optionsHtml(this.headings)).val(keep ? current : '');
      // End Before is derived from Start From.
      this.refreshEndBefore();
    },

    // End Before only offers headings that come after the chosen Start From,
    // since the range must end after it starts.
    refreshEndBefore: function () {
      var startVal = this.$startFrom.val();
      var startIndex = -1;
      for (var i = 0; i < this.headings.length; i++) {
        if (this.headings[i].value === startVal) {
          startIndex = i;
          break;
        }
      }
      var allowed = this.headings.slice(startIndex + 1);

      var current = this.$endBefore.val();
      var keep = allowed.some(function (h) { return h.value === current; });
      this.$endBefore.html(this.optionsHtml(allowed)).val(keep ? current : '');
    },

    optionsHtml: function (headings) {
      var html = '<option value=""></option>';
      headings.forEach(function (h) {
        // Indent sub-headings (h3+) under their section with non-breaking spaces.
        var depth = Math.max(0, (h.level || 2) - 2);
        var pad = new Array(depth + 1).join(String.fromCharCode(160, 160, 160));
        html +=
          '<option value="' +
          Craft.escapeHtml(h.value) +
          '">' +
          pad +
          Craft.escapeHtml(h.label) +
          '</option>';
      });
      return html;
    },
  });
})(jQuery);
