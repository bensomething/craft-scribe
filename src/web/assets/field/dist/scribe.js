/* global Craft, Garnish, jQuery */
(function ($) {
  'use strict';

  // Repo picker + heading range for the Scribe field. When the repo changes,
  // repopulate the Start From / End Before menus from the source's headings.
  Craft.ScribeField = Garnish.Base.extend({
    $url: null,
    $startFrom: null,
    $endBefore: null,
    $range: null,
    $preview: null,
    $previewBody: null,
    settings: null,
    headings: null,

    init: function (id, settings) {
      this.settings = settings;
      this.headings = settings.headings || [];
      this.$url = $('#' + id + '-url');
      this.$startFrom = $('#' + id + '-startFrom');
      this.$endBefore = $('#' + id + '-endBefore');
      this.$range = this.$url.closest('.scribe-field').find('[data-scribe-range]');
      this.$preview = this.$url.closest('.scribe').find('[data-scribe-preview]');
      this.$previewBody = this.$preview.find('[data-scribe-preview-body]');

      this.addListener(this.$url, 'change', 'onChange');
      this.addListener(this.$startFrom, 'change', 'onStartChange');
      this.addListener(this.$endBefore, 'change', 'refreshPreview');
      this.hookRepoClear();

      // Render the heading menus from the server-provided headings.
      if (this.headings.length) {
        this.populate(this.headings);
      }
    },

    onStartChange: function () {
      this.refreshEndBefore();
      this.refreshPreview();
    },

    // Let emptying the repo box clear the field. Craft's select_on_focus plugin
    // restores the value on blur, so we track when the box is emptied and
    // force-clear on the next frame, after that restore runs.
    // (Selectize is set up by Craft's macro just after this, so retry until ready.)
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
        this.$preview.addClass('hidden');
        this.headings = [];
        this.$startFrom.val('');
        this.$endBefore.val('');
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
          self.refreshPreview();
        });
    },

    // Fetch and show the rendered section, debounced. Only when the field has
    // Show Preview on.
    refreshPreview: function () {
      if (!this.settings.preview || !this.$preview.length) {
        return;
      }
      var self = this;
      var url = this.$url.val();
      if (!url) {
        this.$preview.addClass('hidden');
        return;
      }
      this.$preview.removeClass('hidden');
      clearTimeout(this.previewTimer);
      this.previewTimer = setTimeout(function () {
        Craft.sendActionRequest('POST', self.settings.previewAction, {
          data: {
            url: url,
            startFrom: self.$startFrom.val(),
            endBefore: self.$endBefore.val(),
          },
        })
          .then(function (response) {
            self.$previewBody.html((response.data && response.data.html) || '');
          })
          .catch(function () {
            self.$previewBody.html('');
          });
      }, 300);
    },

    populate: function (headings) {
      this.headings = headings || [];
      // Start From offers every heading, keeping the current choice if still valid.
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
      // Blank option carries a non-breaking space so an empty selection keeps
      // the select's line full height, with no gap below it.
      var html = '<option value="">' + String.fromCharCode(160) + '</option>';
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
