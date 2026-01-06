// assets/cenexel-location-leads.js
document.addEventListener("DOMContentLoaded", () => {
  const stepStudies = document.getElementById("cenexel-step-studies");
  const stepForm = document.getElementById("cenexel-step-form");
  const stepThankYou = document.getElementById("cenexel-step-thankyou");
  const form = document.getElementById("cenexel-lead-form");
  const continueBtn = document.getElementById("cenexel-continue-btn");
  const studyError = document.getElementById("cenexel-study-error");
  const studyCheckboxes = document.querySelectorAll(".cenexel-study-checkbox");

  if (!form) return;

  const status = document.getElementById("cenexel-status");

  // Store selected study IDs
  let selectedStudyIds = [];

  // Handle study selection - enable/disable continue button
  if (studyCheckboxes.length > 0 && continueBtn) {
    studyCheckboxes.forEach((checkbox) => {
      checkbox.addEventListener("change", () => {
        selectedStudyIds = Array.from(
          document.querySelectorAll(".cenexel-study-checkbox:checked")
        )
          .map((cb) => Number(cb.value))
          .filter((n) => Number.isFinite(n));

        if (selectedStudyIds.length > 0) {
          continueBtn.disabled = false;
          studyError.style.display = "none";
        } else {
          continueBtn.disabled = true;
        }
      });
    });

    // Handle continue button click
    continueBtn.addEventListener("click", () => {
      if (selectedStudyIds.length === 0) {
        studyError.textContent = "Please select at least one study.";
        studyError.style.display = "block";
        return;
      }

      // Hide studies step, show form step
      if (stepStudies) stepStudies.style.display = "none";
      if (stepForm) {
        stepForm.style.display = "block";
        // Scroll to top of form
        stepForm.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  }

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
      post_ids:
        selectedStudyIds.length > 0
          ? selectedStudyIds
          : fd
              .getAll("post_ids[]")
              .map((x) => Number(x))
              .filter((n) => Number.isFinite(n)),
    };

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
      if (stepForm) stepForm.style.display = "none";
      if (stepThankYou) {
        stepThankYou.style.display = "block";
        stepThankYou.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    } catch (err) {
      status.textContent =
        err?.message || "Submission failed. Please try again.";
      status.className = "cenexel-status-error";
    }
  });
});
