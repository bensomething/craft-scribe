/* global Craft, Garnish, jQuery */
(function ($) {
  'use strict';

  // Readme picker + heading range for the Scribe field. When the readme
  // changes, repopulate the Start From / End Before menus from its headings.
  Craft.ScribeField = Garnish.Base.extend({
    $url: null,
    $startFrom: null,
    $endBefore: null,
    $range: null,
    $note: null,
    $warning: null,
    $warningText: null,
    $spinner: null,
    $refresh: null,
    $preview: null,
    $previewBody: null,
    settings: null,
    headings: null,
    missing: null,
    loadedUrl: '',
    previewAnim: null,
    reposLoaded: false,
    typed: false,
    refreshing: false,
    busy: 0,

    init: function (id, settings) {
      this.settings = settings;
      this.headings = settings.headings || [];
      // The range's own headings, where the readme no longer has them. Held
      // only while they're still the menus' choice, and only for the readme they
      // were saved against.
      this.missing = settings.missing || null;
      this.$url = $('#' + id + '-url');
      this.$startFrom = $('#' + id + '-startFrom');
      this.$endBefore = $('#' + id + '-endBefore');
      this.$range = this.$url.closest('.scribe-field').find('[data-scribe-range]');
      this.$note = this.$url.closest('.scribe-field').find('[data-scribe-note]');
      this.$spinner = this.$url.closest('.scribe-field').find('[data-scribe-spinner]');
      // Beside the card rather than inside it, so it's reached from there rather
      // than through it.
      this.$warning = this.$url.closest('.scribe').siblings('[data-scribe-warning]');
      this.$warningText = this.$warning.find('[data-scribe-warning-text]');
      this.$refresh = this.$url.closest('.scribe').find('[data-scribe-refresh]');
      this.$preview = this.$url.closest('.scribe').find('[data-scribe-preview]');
      this.$previewBody = this.$preview.find('[data-scribe-preview-body]');
      this.loadedUrl = this.$url.val() || '';
      this.hookPreviewState();
      this.hookPreviewAnimation();

      this.addListener(this.$url, 'change', 'onChange');
      this.addListener(this.$startFrom, 'change', 'onStartChange');
      this.addListener(this.$endBefore, 'change', 'onEndChange');
      this.addListener(this.$refresh, 'click', 'onRefresh');
      this.whenSelectized(function (selectize) {
        this.hookRepoClear(selectize);
        this.hookRepoLoad(selectize);
        this.showFilenameOnItem(selectize);
      });

      // Render the heading menus from the server-provided headings. Nothing to
      // render without them, and the markup has already put the note in their
      // place — repopulating would only clear a saved range the field is still
      // holding, which a readme that failed to load should get back.
      if (this.headings.length) {
        this.populate(this.headings, true, this.missing);
      }
    },

    // Drop what Scribe is holding for this readme and fetch it again. A readme
    // is written on GitHub, and the field would otherwise show what was last
    // heard of it there — up to a day ago, on the default cache duration. Only
    // this one readme is dropped: the repository list, which is far dearer to
    // rebuild, isn't what's being asked after.
    onRefresh: function () {
      var self = this;
      // The loaded readme, not the picker's live value, which briefly empties
      // while the picker has focus.
      var url = this.loadedUrl;
      if (!url || this.refreshing) {
        return;
      }
      this.refreshing = true;
      this.$refresh.prop('disabled', true);
      this.setBusy(1);
      Craft.sendActionRequest('POST', this.settings.refreshAction, {
        data: {
          url: url,
          // Sent so the answer can say which of them the refetched readme has
          // lost — the reckoning a page render makes for itself, and a refresh
          // is as likely a moment as any to turn one up.
          startFrom: this.$startFrom.val(),
          endBefore: this.$endBefore.val(),
        },
      })
        .then(function (response) {
          var data = response.data || {};
          self.populate(data.headings || [], !!data.exists, data.missing);
        })
        .catch(function () {
          // Nothing came back, so nothing changes: the field goes on showing the
          // readme it was already showing.
        })
        .finally(function () {
          self.refreshing = false;
          self.$refresh.prop('disabled', false);
          // Hand over before releasing, so the spinner runs on unbroken into the
          // preview fetch this queues.
          self.refreshPreview();
          self.setBusy(-1);
        });
    },

    // Record the pane's open/closed state, under a key PHP built from this
    // instance's own namespaced id, so a field sitting on the page more than
    // once — in each block of a Matrix, say — is remembered a block at a time.
    // Only recorded here: a cookie rather than local storage, so the field
    // renders in the remembered state to begin with. Applying it from here meant
    // a pane the editor had closed was painted open and then shut in front of
    // them.
    hookPreviewState: function () {
      if (!this.$preview.length || !this.settings.previewKey) {
        return;
      }
      // Bound straight to the element: toggle doesn't bubble.
      this.addListener(this.$preview, 'toggle', 'onPreviewToggle');
    },

    onPreviewToggle: function () {
      // A year out, since Craft's default is a cookie that dies with the
      // browser session and this is a preference worth keeping. (Its maxAge
      // option writes an attribute browsers don't recognise, so it's a date.)
      var expires = new Date();
      expires.setFullYear(expires.getFullYear() + 1);
      Craft.setCookie(this.settings.previewKey, this.$preview.prop('open') ? '1' : '0', {
        expires: expires,
      });
    },

    // Open and close the pane on an animation of its own. A details element has
    // none — only the newest browsers can transition one, and the CP runs in
    // more than those — so the toggle's click is taken over: opening happens
    // first and the pane grows into place, and closing runs that backwards and
    // shuts the pane at the end of it.
    hookPreviewAnimation: function () {
      if (!this.$previewBody.length || !this.$previewBody[0].animate) {
        return;
      }
      this.addListener(this.$preview.children('summary'), 'click', 'onPreviewClick');
    },

    onPreviewClick: function (ev) {
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return; // theirs to open in one step, as it would without any of this
      }
      ev.preventDefault();
      var anim = this.previewAnim;

      // Caught mid-flight: turn it around from where it stands, rather than
      // starting the other direction over from an end it never reached.
      if (anim && anim.playState === 'running') {
        anim.reverse();
        this.$preview.toggleClass('closing', anim.playbackRate < 0);
        return;
      }
      if (anim) {
        // A finished close is still holding the pane at nothing; let it go
        // before anything measures the pane it's holding.
        anim.cancel();
      }

      if (this.$preview.prop('open')) {
        // Shut at the end of the collapse rather than the start: the pane has to
        // still be open for there to be anything left to collapse.
        this.$preview.addClass('closing');
        this.previewAnim = this.animatePreview(true);
      } else {
        this.$preview.prop('open', true);
        this.previewAnim = this.animatePreview(false);
      }
    },

    // One animation, written shut-to-open and played backwards to close, so a
    // toggle landing mid-way through another only has to reverse it. The pane
    // grows to the height it lays out at and no further: its rule, the gap above
    // it and its own padding all hold still, so it opens out from under a header
    // that stays where it is rather than everything stretching at once.
    animatePreview: function (closing) {
      var self = this;
      var body = this.$previewBody[0];
      var frames = [
        { height: '0px', opacity: 0 },
        {
          // Measured now rather than left to `auto`, which can't be animated
          // from: a fetched readme has no height that could be set in advance.
          // The pane is border-box, so this is the whole of its height, padding
          // and rule included.
          height: body.getBoundingClientRect().height + 'px',
          opacity: 1,
        },
      ];
      // Off the scrollbar the pane would otherwise flash while it's short of the
      // height its content needs.
      body.style.overflow = 'hidden';
      // Filled at both ends, so the frame between the last one and the pane
      // actually shutting doesn't spring back to full height.
      var anim = body.animate(frames, {
        duration: 200,
        easing: 'ease',
        fill: 'both',
      });
      if (closing) {
        anim.reverse(); // seeks to the open end and runs back from there
      }
      anim.addEventListener('finish', function () {
        body.style.overflow = '';
        if (anim.playbackRate < 0) {
          self.$preview.removeClass('closing').prop('open', false);
          return; // left filled, holding a pane that's no longer rendered
        }
        // Open, so the animation is dropped and the pane goes back to taking its
        // height from its content — which changes under it as readmes load.
        self.previewAnim = null;
        anim.cancel();
      });
      anim.addEventListener('cancel', function () {
        body.style.overflow = '';
      });
      return anim;
    },

    onStartChange: function () {
      this.refreshEndBefore();
      this.refreshWarning();
      this.refreshPreview();
    },

    onEndChange: function () {
      this.refreshWarning();
      this.refreshPreview();
    },

    // Craft's macro selectizes the picker just after this script runs, so retry
    // until it's there. Fields rendered without a token have no picker at all,
    // so there's nothing to wait for.
    whenSelectized: function (callback) {
      var self = this;
      var el = this.$url[0];
      if (!el) {
        return;
      }
      if (!el.selectize) {
        Garnish.requestAnimationFrame(function () {
          self.whenSelectized(callback);
        });
        return;
      }
      callback.call(this, el.selectize);
    },

    // Craft renders the filename hint on dropdown options but not on the
    // selected item — its label helper takes a showHint flag, passed false for
    // items. Swap in a renderer that keeps it, in Craft's own markup.
    showFilenameOnItem: function (selectize) {
      selectize.settings.render.item = function (data) {
        // Craft's own markup for an option's icon, which its dropdown renderer
        // draws for itself — the mark is on the option either way, whether the
        // template named it or the fetched list was handed it.
        var html = data.icon ? '<span class="cp-icon puny">' + data.icon + '</span> ' : '';
        html += '<span>' + Craft.escapeHtml(data.text || '') + '</span>';
        if (data.hint) {
          // En dash separator, as Craft's own hints use.
          html += '<span class="light">\u2013 ' + Craft.escapeHtml(data.hint) + '</span>';
        }
        return '<div class="item"><div class="flex flex-nowrap">' + html + '</div></div>';
      };
      // Re-render the value Craft already drew and cached under its renderer.
      selectize.clearCache('item');
      var value = selectize.getValue();
      if (value) {
        selectize.setValue(value, true); // silent: not a change the field made
      }
    },

    // Let emptying the repo box clear the field. Craft's select_on_focus plugin
    // restores the value on blur, so we track when the box is emptied and
    // force-clear on the next frame, after that restore runs.
    hookRepoClear: function (selectize) {
      var self = this;
      var $input = selectize.$control_input;
      var emptied = false;

      this.addListener($input, 'focus', function () {
        emptied = false;
        // Focus fills the box with the selected repo's own text, which isn't a
        // search the editor typed. addRepos needs to know the difference.
        self.typed = false;
      });
      this.addListener($input, 'input', function () {
        emptied = $input.val() === '';
        self.typed = true;
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

    // The field renders with nothing in the menu but its saved value, since
    // building the repository list costs a GitHub request per hundred repos and
    // holds the page up on a cold cache. Fetch it when the picker is first
    // focused, which is the first moment the menu is read.
    hookRepoLoad: function (selectize) {
      var self = this;
      this.addListener(selectize.$control_input, 'focus', function () {
        self.loadRepos(selectize);
      });
    },

    loadRepos: function (selectize) {
      if (this.reposLoaded) {
        return;
      }
      this.reposLoaded = true; // one attempt per field, hit or miss
      var self = this;
      this.setBusy(1);
      Craft.sendActionRequest('POST', this.settings.reposAction)
        .then(function (response) {
          self.addRepos(selectize, (response.data && response.data.repos) || []);
        })
        .catch(function () {
          // Nothing to show, but the saved value is still there to fall back on.
        })
        .finally(function () {
          self.setBusy(-1);
        });
    },

    // Merge the fetched list into the menu. The saved value is already in it as
    // a bare owner/repo path, so that one is updated rather than added — an add
    // is passed over for a value selectize already holds, which would leave the
    // stand-in label in place of the repository's own name and filename hint.
    addRepos: function (selectize, repos) {
      var self = this;
      repos.forEach(function (repo) {
        var option = {
          value: repo.value,
          text: repo.label,
          hint: (repo.data && repo.data.hint) || '',
          // Named in the template, where the server can resolve it; a fetched
          // option is given the mark itself.
          icon: self.settings.repoIcon || '',
        };
        if (selectize.options[option.value]) {
          selectize.updateOption(option.value, option);
        } else {
          selectize.addOption(option);
        }
      });
      // Redraw on the fetched list, opening the menu if the picker still holds
      // focus — which it will, since the fetch was its own focus that started
      // it. Passing false here doesn't leave an open menu alone: selectize
      // closes it, which left the editor clicking a second time for the list
      // they'd already asked for.
      //
      // Craft's select_on_focus plugin puts the selected repo's text in the
      // search box on focus and stops selectize scoring against it — but only
      // until the end of that tick, long before this list lands. Left alone,
      // that untyped text is a live query by now, and would filter the list
      // down to the one repo it names: the editor saw no list until they
      // clicked away and back, which suspends scoring afresh. So suspend it
      // again for this one redraw, exactly as the plugin does on focus, unless
      // the editor has since typed a search that's theirs to be filtered by.
      var score = selectize.settings.score;
      if (!this.typed) {
        selectize.settings.score = function () {
          return function () {
            return 1;
          };
        };
      }
      selectize.refreshOptions(this.isPickerFocused());
      selectize.settings.score = score;
    },

    // True while the picker's own text box holds focus. Checked against the
    // document rather than a flag of our own, since selectize clears the value
    // before it marks itself focused.
    isPickerFocused: function () {
      var selectize = this.$url[0] && this.$url[0].selectize;
      return !!selectize && selectize.$control_input[0] === document.activeElement;
    },

    onChange: function () {
      var url = this.$url.val() || '';
      // Focusing the picker empties it (Craft's select_on_focus plugin, which
      // puts the value back on blur), firing a change for a value the user
      // hasn't touched. Neither half of that is a readme change, so don't tear
      // the range and preview down and refetch them around a focus.
      if (!url && this.isPickerFocused()) {
        return;
      }
      if (url === this.loadedUrl) {
        return;
      }
      this.loadedUrl = url;
      // A range that outlived its headings outlives them in the readme it was
      // saved against, and nowhere else — so it's dropped along with that
      // readme, warning and all, before the new one's headings arrive.
      this.missing = null;
      this.refreshWarning();
      // Nothing to ask after again until there's a readme chosen.
      this.$refresh.toggleClass('hidden', !url);

      if (!url) {
        this.$range.addClass('hidden');
        this.setNote(''); // nothing chosen, so there's nothing to explain
        this.headings = [];
        this.$startFrom.val('');
        this.$endBefore.val('');
        this.refreshPreview(); // hides the pane and drops any pending fetch
        return;
      }
      this.loadHeadings(url);
    },

    // Spin while any request is in flight. Counted, since the headings and
    // preview fetches overlap.
    setBusy: function (delta) {
      this.busy = Math.max(0, this.busy + delta);
      this.$spinner.toggleClass('spinning', this.busy > 0);
    },

    loadHeadings: function (url) {
      var self = this;
      this.setBusy(1);
      Craft.sendActionRequest('POST', this.settings.headingsAction, {
        data: { url: url },
      })
        .then(function (response) {
          var data = response.data || {};
          self.populate(data.headings || [], !!data.exists);
        })
        .catch(function () {
          // The request itself failed, which is as good as an unreachable
          // readme from here.
          self.populate([], false);
        })
        .finally(function () {
          // Hand over before releasing, so the spinner runs on unbroken into
          // the preview fetch this queues.
          self.refreshPreview();
          self.setBusy(-1);
        });
    },

    // Fetch and show the rendered section, debounced. Only when the field has
    // Show Preview on.
    refreshPreview: function () {
      if (!this.settings.preview || !this.$preview.length) {
        return;
      }
      var self = this;
      // The loaded readme, not the picker's live value, which briefly empties
      // while the picker has focus.
      var url = this.loadedUrl;
      if (!url) {
        this.dropQueuedPreview();
        this.$preview.addClass('hidden');
        return;
      }
      // The pane isn't shown ahead of the fetch: whether there's anything to
      // show is the fetch's answer to give, and un-hiding first would flash an
      // empty pane in front of a readme that turns out to be unreachable.
      this.dropQueuedPreview();
      // Hold the spinner from the moment the fetch is queued, so it doesn't
      // blink out over the debounce. The hold passes to the request itself when
      // the timer fires, and is released when that settles.
      this.previewQueued = true;
      this.setBusy(1);
      this.previewTimer = setTimeout(function () {
        self.previewQueued = false;
        Craft.sendActionRequest('POST', self.settings.previewAction, {
          data: {
            url: url,
            startFrom: self.$startFrom.val(),
            endBefore: self.$endBefore.val(),
            // So the preview matches what the front end will render.
            hideImages: self.settings.hideImages ? '1' : '',
          },
        })
          .then(function (response) {
            self.showPreview(response.data ? response.data.html : null);
          })
          .catch(function () {
            self.showPreview(null);
          })
          .finally(function () {
            self.setBusy(-1);
          });
      }, 300);
    },

    // Cancel a preview fetch that's queued but hasn't fired, releasing its hold
    // on the spinner. Anything already in flight releases itself.
    dropQueuedPreview: function () {
      clearTimeout(this.previewTimer);
      if (this.previewQueued) {
        this.previewQueued = false;
        this.setBusy(-1);
      }
    },

    // `exists` says whether the readme was reached at all, which is what tells
    // a readme with no headings apart from one that couldn't be loaded — both
    // arrive here as an empty list. `missing` is the range's own headings that
    // this readme hasn't got, and is only ever given for a readme the field was
    // already on: the range doesn't follow an editor to a readme they've just
    // picked, so neither does anything said about it.
    populate: function (headings, exists, missing) {
      this.headings = headings || [];
      this.missing = missing || null;
      // A readme with no headings has no range to offer, so the menus leave the
      // field rather than sit there holding nothing but their placeholders, and
      // a note takes their place saying why.
      this.$range.toggleClass('hidden', !this.headings.length);
      this.setNote(
        this.headings.length
          ? ''
          : exists
            ? this.settings.noHeadingsText
            : this.settings.loadFailedText
      );
      // Start From offers every heading, keeping the current choice if still
      // valid — or if it's one the readme has lost, which is kept on the end of
      // the menu rather than dropped out from under the range it's half of.
      var stale = this.staleOption('startFrom');
      var current = this.$startFrom.val();
      var keep =
        this.headings.some(function (h) { return h.value === current; }) || !!stale;
      this.$startFrom
        .html(this.optionsHtml(this.headings, this.settings.startPlaceholder, stale))
        .val(keep ? current : '');
      // End Before is derived from Start From.
      this.refreshEndBefore();
      this.refreshWarning();
    },

    // The heading a menu is set to that its readme no longer has, as the option
    // standing in for it — or null once the editor has chosen another in its
    // place, and for a range that was never broken to begin with.
    staleOption: function (which) {
      var option = this.missing && this.missing[which];
      var $menu = which === 'startFrom' ? this.$startFrom : this.$endBefore;
      return option && $menu.val() === option.value ? option : null;
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

      var stale = this.staleOption('endBefore');
      var current = this.$endBefore.val();
      var keep = allowed.some(function (h) { return h.value === current; }) || !!stale;
      this.$endBefore
        .html(this.optionsHtml(allowed, this.settings.endPlaceholder, stale))
        .val(keep ? current : '');
    },

    // The warning under the field, which stands only while a menu is still set
    // to a heading its readme has lost. Worded here as well as in the template,
    // so it keeps up as the editor answers it: choosing a heading in place of
    // one of the two leaves it saying what's still true, and answering both
    // takes it out of the field.
    refreshWarning: function () {
      if (!this.$warning.length) {
        return;
      }
      var start = this.staleOption('startFrom');
      var end = this.staleOption('endBefore');
      var text = '';
      if (start && end) {
        text = this.settings.staleBothText;
      } else if (start) {
        text = this.settings.staleStartText;
      } else if (end) {
        text = this.settings.staleEndText;
      }
      this.$warningText.text(text);
      this.$warning.toggleClass('hidden', !text);
    },

    // Fill the pane, or take it out of the field when there's nothing to fill it
    // with. Null html is a readme that couldn't be fetched — the note above says
    // as much, so an empty pane under it would only repeat the point.
    showPreview: function (html) {
      this.$preview.toggleClass('hidden', html === null || html === undefined);
      this.$previewBody.html(html || '');
    },

    // Text into the note, or empty to take it back out of the row. Set through
    // .text(), so the note is a live region that announces itself only when
    // there's something new in it.
    setNote: function (text) {
      this.$note.text(text || '').toggleClass('hidden', !text);
    },

    optionsHtml: function (headings, placeholder, stale) {
      // The blank option doubles as the menu's placeholder, since these menus
      // have no visible label.
      var html = '<option value="">' + Craft.escapeHtml(placeholder || '') + '</option>';
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
      // The heading the readme has lost goes on the end, out of the order it
      // once had: it's the menu's own choice rather than one of the readme's,
      // and it's marked as missing where the rest are named.
      if (stale) {
        html +=
          '<option value="' +
          Craft.escapeHtml(stale.value) +
          '">' +
          Craft.escapeHtml(stale.label) +
          '</option>';
      }
      return html;
    },
  });
})(jQuery);
