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
  const formWrapper = document.getElementById("cenexel-form-wrapper");
  const thankYou = document.getElementById("cenexel-step-thankyou");
  const form = document.getElementById("cenexel-lead-form");
  const studyError = document.getElementById("cenexel-study-error");
  const studyCheckboxes = document.querySelectorAll(".cenexel-study-checkbox");

  if (!form) return;

  const status = document.getElementById("cenexel-status");

  // Store selected campaign activities
  let selectedCampaignActivities = [];

  const getSelectedCampaignActivities = () =>
    Array.from(document.querySelectorAll(".cenexel-study-checkbox:checked"))
      .map((cb) => (cb.value || "").toString().trim())
      .filter(Boolean);

  const getSelectedStudies = () =>
    Array.from(document.querySelectorAll(".cenexel-study-checkbox:checked"))
      .map((cb) => ({
        title: (cb.dataset.title || "").trim(),
        campaign_activity: (cb.value || "").toString().trim(),
      }))
      .filter((s) => s.campaign_activity);

  const updateSelectionState = () => {
    selectedCampaignActivities = getSelectedCampaignActivities();
    if (studyError) studyError.style.display = "none";
  };

  // Handle study selection changes
  studyCheckboxes.forEach((checkbox) => {
    checkbox.addEventListener("change", () => {
      updateSelectionState();
    });
  });

  // Handle "Other" checkbox toggle
  const otherCheckbox = document.getElementById("cenexel-study-other");
  const otherField = document.getElementById("cenexel-study-other-field");
  const otherText = document.getElementById("cenexel-study-other-text");
  if (otherCheckbox && otherField) {
    otherCheckbox.addEventListener("change", () => {
      otherField.style.display = otherCheckbox.checked ? "block" : "none";
      if (!otherCheckbox.checked && otherText) otherText.value = "";
    });
  }

  // Build date_of_birth from dropdowns
  const buildDob = () => {
    const year = (form.querySelector('[name="dob_year"]')?.value || "").trim();
    const month = (form.querySelector('[name="dob_month"]')?.value || "").trim();
    const day = (form.querySelector('[name="dob_day"]')?.value || "").trim();
    if (!year) return "";
    const mm = month ? month.padStart(2, "0") : "";
    const dd = day ? day.padStart(2, "0") : "";
    if (mm && dd) return `${year}-${mm}-${dd}`;
    if (mm) return `${year}-${mm}`;
    return year;
  };

  // Validation
  const validationBox = document.getElementById("cenexel-validation-errors");

  const validateForm = () => {
    const missing = [];
    const val = (name) => (form.querySelector(`[name="${name}"]`)?.value || "").trim();

    if (!val("first_name")) missing.push("First Name");
    if (!val("last_name")) missing.push("Last Name");
    if (!val("phone")) missing.push("Phone");
    if (!val("zip")) missing.push("ZIP/Postal Code");
    if (!val("dob_year")) missing.push("Date of Birth (Year)");
    if (!form.querySelector('[name="consent"]')?.checked) missing.push("Privacy Policy consent");

    return missing;
  };

  const showValidationErrors = (missing) => {
    if (!validationBox) return;
    validationBox.innerHTML =
      "<strong>Please complete the following required fields:</strong>" +
      "<ul>" +
      missing.map((f) => `<li>${f}</li>`).join("") +
      "</ul>";
    validationBox.style.display = "block";
    validationBox.scrollIntoView({ behavior: "smooth", block: "center" });
  };

  const clearValidationErrors = () => {
    if (validationBox) {
      validationBox.style.display = "none";
      validationBox.innerHTML = "";
    }
  };

  // Handle form submission
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    clearValidationErrors();

    // Validate all required fields
    const missing = validateForm();
    if (missing.length > 0) {
      showValidationErrors(missing);
      return;
    }

    selectedCampaignActivities = getSelectedCampaignActivities();

    status.textContent = "Submitting...";
    status.className = "cenexel-status-loading";

    const fd = new FormData(form);
    const emailValue = (fd.get("email") || "").toString().trim();
    const payload = {
      location_term_id: Number(fd.get("location_term_id")),
      site_slug: (fd.get("site_slug") || "").toString(),
      first_name: (fd.get("first_name") || "").toString().trim(),
      last_name: (fd.get("last_name") || "").toString().trim(),
      email: emailValue || "noemail@cenexel.com",
      phone: (fd.get("phone") || "").toString().trim(),
      zip: (fd.get("zip") || "").toString().trim(),
      date_of_birth: buildDob(),
      gender: (fd.get("gender") || "").toString(),
      is_caregiver:
        fd.get("is_caregiver") === "1" || fd.get("is_caregiver") === "on",
      consent: fd.get("consent") === "on",
      sms_consent: fd.get("sms_consent") === "on",
      campaign_activities: selectedCampaignActivities,
      studies: getSelectedStudies(),
      study_other: otherCheckbox?.checked ? (otherText?.value || "").trim() : "",
      event: new URLSearchParams(window.location.search).get("event") || "",
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

      // Success - show thank you, hide form
      if (formWrapper) formWrapper.style.display = "none";
      if (thankYou) {
        thankYou.style.display = "block";
        thankYou.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    } catch (err) {
      status.textContent =
        err?.message || "Submission failed. Please try again.";
      status.className = "cenexel-status-error";
    }
  });

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
      // Reset status and validation
      if (status) {
        status.textContent = "";
        status.className = "";
      }
      clearValidationErrors();
      // Show form, hide thank you
      if (formWrapper) formWrapper.style.display = "block";
      if (thankYou) thankYou.style.display = "none";
      // Scroll to top
      formWrapper.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }
});
