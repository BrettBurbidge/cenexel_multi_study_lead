// assets/cenexel-location-leads.js
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("cenexel-lead-form");
  if (!form) return;

  const status = document.getElementById("cenexel-status");

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    status.textContent = "Submitting...";

    const fd = new FormData(form);
    const payload = {
      location_term_id: Number(fd.get("location_term_id")),
      site_slug: (fd.get("site_slug") || "").toString(),
      first_name: (fd.get("first_name") || "").toString().trim(),
      last_name: (fd.get("last_name") || "").toString().trim(),
      email: (fd.get("email") || "").toString().trim(),
      phone: (fd.get("phone") || "").toString().trim(),
      zip: (fd.get("zip") || "").toString().trim(),
      consent: fd.get("consent") === "on",
      post_ids: fd
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

      status.textContent = "Thanks — we received your request.";
      form.reset();
    } catch (err) {
      status.textContent = err?.message || "Submission failed.";
    }
  });
});
