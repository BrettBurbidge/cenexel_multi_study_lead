// assets/cenexel-location-leads.js

/**
 * UTM Parameter Tracking Module
 * Captures UTM values from URL query string (priority 1) or WordPress cookies (priority 2)
 * with configurable defaults
 */
(function () {
  "use strict";

  // Configuration: Set default UTM values here (can be overridden by URL or cookies)
  const UTM_DEFAULTS = {
    utm_source: "", // e.g., "direct" or leave empty
    utm_medium: "", // e.g., "none" or leave empty
    utm_campaign: "",
    utm_content: "",
    utm_term: "",
  };

  const UTM_PARAMS = [
    "utm_source",
    "utm_medium",
    "utm_campaign",
    "utm_content",
    "utm_term",
  ];

  /**
   * Get cookie value by name
   */
  function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) {
      return decodeURIComponent(parts.pop().split(";").shift());
    }
    return null;
  }

  /**
   * Set cookie with expiration
   */
  function setCookie(name, value, days = 30) {
    const d = new Date();
    d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
    const expires = `expires=${d.toUTCString()}`;
    document.cookie = `${name}=${encodeURIComponent(
      value
    )};${expires};path=/;SameSite=Lax`;
  }

  /**
   * Get URL query parameter
   */
  function getQueryParam(name) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(name);
  }

  /**
   * Capture UTM parameters with priority: URL > Cookies > Defaults
   */
  function captureUTMParameters() {
    const captured = {};

    UTM_PARAMS.forEach((param) => {
      let value = null;

      // Priority 1: URL query string (highest priority)
      value = getQueryParam(param);

      // Priority 2: WordPress cookies
      if (!value) {
        // Check common WordPress cookie formats
        value =
          getCookie(param) || // Plain format: utm_source
          getCookie(`_ga_${param}`) || // Google Analytics: _ga_utm_source
          getCookie(`wp_${param}`); // WordPress plugin: wp_utm_source
      }

      // Priority 3: Defaults from configuration
      if (!value && UTM_DEFAULTS[param]) {
        value = UTM_DEFAULTS[param];
      }

      if (value) {
        captured[param] = value;
        // Store in cookie for persistence (30 days)
        setCookie(param, value, 30);
      }
    });

    return captured;
  }

  /**
   * Get first-touch UTM values (original acquisition source)
   */
  function getFirstTouchUTM() {
    const firstTouch = {};

    UTM_PARAMS.forEach((param) => {
      const firstValue = getCookie(`first_${param}`);
      if (firstValue) {
        firstTouch[`first_${param}`] = firstValue;
      }
    });

    return firstTouch;
  }

  /**
   * Store first-touch attribution (only if not already set)
   */
  function storeFirstTouch(utmValues) {
    Object.keys(utmValues).forEach((key) => {
      const firstKey = `first_${key}`;
      if (!getCookie(firstKey)) {
        // Set first-touch cookie for 365 days (1 year)
        setCookie(firstKey, utmValues[key], 365);
      }
    });
  }

  // Capture UTM on page load
  const currentUTM = captureUTMParameters();

  // Store first-touch if this is their first visit with UTM
  if (Object.keys(currentUTM).length > 0) {
    storeFirstTouch(currentUTM);
  }

  // Make available globally for form submission
  window.CENEXEL_UTM = {
    current: currentUTM,
    firstTouch: getFirstTouchUTM(),
  };
})();

document.addEventListener("DOMContentLoaded", () => {
  const stepStudies = document.getElementById("cenexel-step-studies");
  const stepForm = document.getElementById("cenexel-step-form");
  const stepThankYou = document.getElementById("cenexel-step-thankyou");
  const form = document.getElementById("cenexel-lead-form");
  const continueBtn = document.getElementById("cenexel-continue-btn");
  const studyError = document.getElementById("cenexel-study-error");
  const studyCheckboxes = document.querySelectorAll(".cenexel-study-checkbox");
  const studyCards = document.querySelectorAll(".cenexel-study-card");

  if (!form) return;

  const status = document.getElementById("cenexel-status");

  // Store selected campaign activities
  let selectedCampaignActivities = [];

  const getSelectedCampaignActivities = () =>
    Array.from(document.querySelectorAll(".cenexel-study-checkbox:checked"))
      .map((cb) => (cb.value || "").toString().trim())
      .filter(Boolean);

  const updateContinueState = () => {
    selectedCampaignActivities = getSelectedCampaignActivities();
    if (continueBtn) continueBtn.disabled = selectedCampaignActivities.length === 0;
    if (studyError) studyError.style.display = "none";
  };

  // Step navigation with History API
  const showStep = (stepName, addToHistory = true) => {
    // Hide all steps
    if (stepStudies) stepStudies.style.display = "none";
    if (stepForm) stepForm.style.display = "none";
    if (stepThankYou) stepThankYou.style.display = "none";

    // Show requested step
    if (stepName === "studies" && stepStudies) {
      stepStudies.style.display = "block";
      stepStudies.scrollIntoView({ behavior: "smooth", block: "start" });
      if (addToHistory) {
        const url = new URL(window.location);
        url.searchParams.delete("step");
        window.history.pushState({ step: "studies" }, "", url);
      }
    } else if (stepName === "form" && stepForm) {
      stepForm.style.display = "block";
      stepForm.scrollIntoView({ behavior: "smooth", block: "start" });
      if (addToHistory) {
        const url = new URL(window.location);
        url.searchParams.set("step", "form");
        window.history.pushState({ step: "form" }, "", url);
      }
    } else if (stepName === "thankyou" && stepThankYou) {
      stepThankYou.style.display = "block";
      stepThankYou.scrollIntoView({ behavior: "smooth", block: "start" });
      if (addToHistory) {
        const url = new URL(window.location);
        url.searchParams.set("step", "thankyou");
        window.history.pushState({ step: "thankyou" }, "", url);
      }
    }
  };

  // Handle browser back/forward buttons
  window.addEventListener("popstate", (e) => {
    const step = e.state?.step || "studies";
    showStep(step, false); // Don't add to history since we're already navigating history
  });

  // Initialize step based on URL parameter and set initial history state
  const urlParams = new URLSearchParams(window.location.search);
  const initialStep = urlParams.get("step");
  if (initialStep === "form") {
    showStep("form", false);
    // Set initial state so back button works
    window.history.replaceState({ step: "form" }, "", window.location);
  } else if (initialStep === "thankyou") {
    showStep("thankyou", false);
    window.history.replaceState({ step: "thankyou" }, "", window.location);
  } else {
    // Ensure studies step is visible initially
    showStep("studies", false);
    // Remove step param if it exists, otherwise keep URL as-is
    if (initialStep) {
      urlParams.delete("step");
      const newUrl =
        window.location.pathname +
        (urlParams.toString() ? "?" + urlParams.toString() : "");
      window.history.replaceState({ step: "studies" }, "", newUrl);
    } else {
      // No step param, set initial state without modifying URL
      window.history.replaceState({ step: "studies" }, "", window.location);
    }
  }

  // --- Filters ---
  const filtersToggle = document.querySelector(".cenexel-study-filters-toggle");
  const filtersPanel = document.getElementById("cenexel-study-filters");
  const sexRadios = document.querySelectorAll(
    'input[name="cenexel_filter_sex"]'
  );
  const ageChecks = document.querySelectorAll(
    'input[name="cenexel_filter_age"]'
  );
  const clearFiltersBtn = document.getElementById("cenexel-clear-filters");

  // Toggle filters panel
  if (filtersToggle && filtersPanel) {
    filtersToggle.addEventListener("click", () => {
      const isExpanded = filtersToggle.getAttribute("aria-expanded") === "true";
      const newExpanded = !isExpanded;

      filtersToggle.setAttribute("aria-expanded", newExpanded.toString());
      filtersPanel.style.display = newExpanded ? "block" : "none";
      filtersToggle.querySelector(
        ".cenexel-study-filters-toggle-text"
      ).textContent = newExpanded ? "Hide Filters" : "Show Filters";
    });
  }

  const getActiveFilters = () => {
    const sex = Array.from(sexRadios).find((r) => r.checked)?.value || "all";
    const ages = new Set(
      Array.from(ageChecks)
        .filter((c) => c.checked)
        .map((c) => c.value)
    );
    return { sex, ages };
  };

  const rowMatches = (row, filters) => {
    const gender = (row.dataset.gender || "unknown").toLowerCase();
    const buckets = new Set(
      (row.dataset.ageBuckets || "")
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean)
    );

    // Sex filter: if female/male, include exact match OR "all" (open to all)
    if (filters.sex !== "all") {
      if (!(gender === filters.sex || gender === "all")) return false;
    }

    // Age filter: if any selected, require intersection; unknown buckets fail
    if (filters.ages.size > 0) {
      let ok = false;
      for (const a of filters.ages) {
        if (buckets.has(a)) ok = true;
      }
      if (!ok) return false;
    }

    return true;
  };

  const applyFilters = () => {
    const filters = getActiveFilters();

    studyCards.forEach((card) => {
      const visible = rowMatches(card, filters);
      card.style.display = visible ? "" : "none";

      // If a card is hidden, uncheck it so Continue doesn't stay enabled invisibly
      if (!visible) {
        const cb = card.querySelector(".cenexel-study-checkbox");
        if (cb && cb.checked) {
          cb.checked = false;
          cb.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
    });

    updateContinueState();
  };

  const clearFilters = () => {
    // Sex -> All
    const all = Array.from(sexRadios).find((r) => r.value === "all");
    if (all) all.checked = true;
    // Ages -> none
    ageChecks.forEach((c) => (c.checked = false));
    applyFilters();
  };

  // Wire filter events
  sexRadios.forEach((r) => r.addEventListener("change", applyFilters));
  ageChecks.forEach((c) => c.addEventListener("change", applyFilters));
  if (clearFiltersBtn) clearFiltersBtn.addEventListener("click", clearFilters);

  // Handle study selection - enable/disable continue button
  if (studyCheckboxes.length > 0 && continueBtn) {
    studyCheckboxes.forEach((checkbox) => {
      checkbox.addEventListener("change", () => {
        updateContinueState();
      });
    });

    // Handle continue button click
    continueBtn.addEventListener("click", () => {
      if (selectedCampaignActivities.length === 0) {
        studyError.textContent = "Please select at least one study.";
        studyError.style.display = "block";
        return;
      }

      // Navigate to form step
      showStep("form");
    });
  }

  // Make study cards clickable (but not the title link)
  studyCards.forEach((card) => {
    card.addEventListener("click", (e) => {
      // Don't toggle if clicking the checkbox itself or a link
      if (
        e.target.classList.contains("cenexel-study-checkbox") ||
        e.target.closest(".cenexel-study-title") ||
        e.target.tagName === "A"
      ) {
        return;
      }

      // Toggle the checkbox
      const checkbox = card.querySelector(".cenexel-study-checkbox");
      if (checkbox) {
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });
  });

  // Handle form submission
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    status.textContent = "Submitting...";
    status.className = "cenexel-status-loading";

    const fd = new FormData(form);
    const payload = {
      location_term_id: Number(fd.get("location_term_id")),
      site_slug: (fd.get("site_slug") || "").toString(),
      first_name: (fd.get("first_name") || "").toString().trim(),
      last_name: (fd.get("last_name") || "").toString().trim(),
      email: (fd.get("email") || "").toString().trim(),
      phone: (fd.get("phone") || "").toString().trim(),
      zip: (fd.get("zip") || "").toString().trim(),
      date_of_birth: (fd.get("date_of_birth") || "").toString().trim(),
      gender: (fd.get("gender") || "").toString(),
      is_caregiver:
        fd.get("is_caregiver") === "1" || fd.get("is_caregiver") === "on",
      consent: fd.get("consent") === "on",
      sms_consent: fd.get("sms_consent") === "on",
      campaign_activities:
        selectedCampaignActivities.length > 0
          ? selectedCampaignActivities
          : fd
              .getAll("campaign_activities[]")
              .map((x) => (x || "").toString().trim())
              .filter(Boolean),
      // Add UTM parameters (last-touch attribution)
      ...window.CENEXEL_UTM?.current,
      // Add first-touch attribution
      ...window.CENEXEL_UTM?.firstTouch,
    };

    console.log("CenExel lead payload (testing)", payload);

    try {
      const res = await fetch(CENEXEL.restUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": CENEXEL.nonce,
        },
        body: JSON.stringify(payload),
      });

      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json.error || `Failed (${res.status})`);

      // Success - show thank you page
      showStep("thankyou");
    } catch (err) {
      status.textContent =
        err?.message || "Submission failed. Please try again.";
      status.className = "cenexel-status-error";
    }
  });

  // Handle back button on form page
  const backToStudiesFromFormBtn = document.getElementById(
    "cenexel-back-to-studies-from-form"
  );
  if (backToStudiesFromFormBtn) {
    backToStudiesFromFormBtn.addEventListener("click", () => {
      // Navigate back to studies step
      showStep("studies");
    });
  }

  // Handle back button on thank you page
  const backToStudiesBtn = document.getElementById(
    "cenexel-back-to-studies-btn"
  );
  if (backToStudiesBtn) {
    backToStudiesBtn.addEventListener("click", () => {
      // Reset form
      if (form) form.reset();
      // Clear selected studies
      selectedCampaignActivities = [];
      studyCheckboxes.forEach((checkbox) => {
        checkbox.checked = false;
      });
      if (continueBtn) continueBtn.disabled = true;
      // Reset filters too
      clearFilters();
      // Collapse filters panel
      if (filtersToggle && filtersPanel) {
        filtersToggle.setAttribute("aria-expanded", "false");
        filtersPanel.style.display = "none";
        filtersToggle.querySelector(
          ".cenexel-study-filters-toggle-text"
        ).textContent = "Show Filters";
      }
      // Navigate back to studies
      showStep("studies");
    });
  }

  // Initial filter application (in case defaults should hide/show)
  applyFilters();
});
