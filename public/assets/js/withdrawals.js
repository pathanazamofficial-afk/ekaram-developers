document.querySelectorAll("form[data-withdrawal-details]").forEach((form) => {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const output = form.parentElement.querySelector("[data-withdrawal-output]");
    const button = form.querySelector("button[type='submit']");
    if (button) button.disabled = true;
    if (output) output.textContent = "Loading...";

    try {
      const response = await fetch(form.getAttribute("action"), {
        method: "POST",
        body: new FormData(form),
        headers: { Accept: "application/json" }
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        if (output) output.textContent = result.message || "Payout details could not be loaded.";
        return;
      }
      const details = result.details;
      if (output) {
        const bank = details.bank_name || details.branch_location || "";
        output.textContent = `${details.account_name} · ${bank} · ${details.account_number} · IFSC ${details.ifsc}`;
        if (details.branch_location) output.textContent += ` · ${details.branch_location}`;
      }
    } catch (error) {
      if (output) output.textContent = "Payout details could not be loaded.";
    } finally {
      if (button) button.disabled = false;
    }
  });
});
