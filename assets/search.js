(function () {
  "use strict";

  var input = document.getElementById("bible-qa-search");
  var results = document.getElementById("bible-qa-results");

  if (!input || !results) {
    return;
  }
  if (typeof BibleQA === "undefined") {
    console.warn("BQA: BibleQA object not localized.");
    return;
  }

  /* =====================================================================
   * Autocomplete dropdown
   * ================================================================== */

  var dropdown = document.createElement("div");
  dropdown.className = "bqa-suggest";
  dropdown.setAttribute("role", "listbox");
  dropdown.style.display = "none";
  input.parentNode.style.position = "relative";
  input.parentNode.appendChild(dropdown);

  var suggestTimer;
  var searchTimer;
  var suggestController;
  var searchController;

  var activeIndex = -1;
  var suggestItems = [];

  input.addEventListener("input", function (e) {
    var q = e.target.value.trim();

    // Clear results pane on every keystroke — prevents stale messages
    results.innerHTML = "";

    if (q.length < 2) {
      hideSuggest();
      return;
    }

    clearTimeout(suggestTimer);
    suggestTimer = setTimeout(function () {
      runSuggest(q);
    }, 150);

    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () {
      runSearch(q);
    }, 400);
  });

  input.addEventListener("keydown", function (e) {
    if (dropdown.style.display === "none") return;

    if (e.key === "ArrowDown") {
      e.preventDefault();
      activeIndex = Math.min(activeIndex + 1, suggestItems.length - 1);
      renderSuggest();
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      activeIndex = Math.max(activeIndex - 1, -1);
      renderSuggest();
    } else if (e.key === "Enter") {
      if (activeIndex >= 0 && suggestItems[activeIndex]) {
        e.preventDefault();
        window.location.href = suggestItems[activeIndex].url;
      } else {
        hideSuggest();
      }
    } else if (e.key === "Escape") {
      hideSuggest();
      input.blur();
    }
  });

  input.addEventListener("blur", function () {
    // Delay so a click on the dropdown registers first
    setTimeout(hideSuggest, 200);
  });

  function runSuggest(q) {
    if (suggestController) suggestController.abort();
    suggestController = new AbortController();

    var url = BibleQA.root + "suggest?q=" + encodeURIComponent(q);

    fetch(url, {
      headers: { "X-WP-Nonce": BibleQA.nonce },
      signal: suggestController.signal,
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        suggestItems = data.suggestions || [];
        activeIndex = -1;
        if (suggestItems.length === 0) {
          hideSuggest();
        } else {
          renderSuggest();
        }
      })
      .catch(function (err) {
        if (err.name !== "AbortError") {
          hideSuggest();
        }
      });
  }

  function renderSuggest() {
    if (suggestItems.length === 0) {
      hideSuggest();
      return;
    }

    var html = "";
    suggestItems.forEach(function (item, i) {
      var cls =
        "bqa-suggest-item" + (i === activeIndex ? " bqa-suggest-active" : "");
      html +=
        '<a class="' +
        cls +
        '" role="option" data-url="' +
        encodeURI(item.url) +
        '">' +
        escapeHtml(item.question) +
        "</a>";
    });

    dropdown.innerHTML = html;
    dropdown.style.display = "block";

    // Click handlers
    dropdown.querySelectorAll(".bqa-suggest-item").forEach(function (el) {
      el.addEventListener("mousedown", function (e) {
        e.preventDefault();
        window.location.href = el.getAttribute("data-url");
      });
    });
  }

  function hideSuggest() {
    dropdown.style.display = "none";
    activeIndex = -1;
  }

  /* =====================================================================
   * Full search results
   * ================================================================== */

  function runSearch(q) {
    if (searchController) searchController.abort();
    searchController = new AbortController();

    results.innerHTML = '<p class="bqa-loading">Searching…</p>';

    var url = BibleQA.root + "search?q=" + encodeURIComponent(q);

    fetch(url, {
      headers: { "X-WP-Nonce": BibleQA.nonce },
      signal: searchController.signal,
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        var items = data.results || [];

        if (items.length === 0 && suggestItems.length > 0) {
          results.innerHTML =
            '<p class="bqa-hint">Select one of the suggestions to see the full answer.</p>';
          return;
        }

        if (items.length === 0) {
          results.innerHTML = '<p class="bqa-empty">No results found.</p>';
          return;
        }

        renderResults(items);
      })
      .catch(function (err) {
        if (err.name !== "AbortError") {
          results.innerHTML =
            '<p class="bqa-error">Search failed. Try again.</p>';
        }
      });
  }

  function renderResults(items) {
    if (!items.length) {
      results.innerHTML = '<p class="bqa-empty">No results found.</p>';
      return;
    }

    results.innerHTML = items
      .map(function (item) {
        return (
          '<a class="bqa-result" href="' +
          encodeURI(item.slug ? "/qa/" + item.slug + "/" : "#") +
          '">' +
          "<h4>" +
          escapeHtml(item.question) +
          "</h4>" +
          "<p>" +
          escapeHtml(item.excerpt) +
          "…</p>" +
          "</a>"
        );
      })
      .join("");
  }

  /* =====================================================================
   * Utils
   * ================================================================== */

  function escapeHtml(s) {
    var d = document.createElement("div");
    d.textContent = s || "";
    return d.innerHTML;
  }
})();
