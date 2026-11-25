const adminMessage = document.querySelector(".admin-message");

document.querySelectorAll("form[data-admin-form]").forEach((form) => {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const message = form.querySelector("[data-admin-message]") || adminMessage;
    const formData = new FormData(form);
    const action = formData.get("action");
    const rejectionReason = form.querySelector("[name='admin_remarks']");
    if (action === "reject" && (!rejectionReason || !rejectionReason.value.trim())) {
      if (message) message.textContent = "A rejection reason is required.";
      rejectionReason?.focus();
      return;
    }
    const defaultConfirmations = {
      reject: "Reject this Marketing Associate profile?",
      block: "Block this Marketing Associate profile?",
      unblock: "Unblock this Marketing Associate profile?"
    };
    const confirmation = form.dataset.confirm || defaultConfirmations[action];
    if (confirmation && !window.confirm(confirmation)) return;
    const button = form.querySelector("button[type='submit']");
    if (button) button.disabled = true;
    if (message) message.textContent = "Saving...";

    try {
      const response = await fetch(form.getAttribute("action"), {
        method: "POST",
        body: formData,
        headers: { Accept: "application/json" }
      });
      const result = await response.json();
      if (message) {
        message.textContent = result.message || "Request complete.";
        if (result.success && typeof result.invite_url === "string" && result.invite_url.startsWith("/")) {
          message.textContent += ` Share this link: ${new URL(result.invite_url, window.location.origin).href}`;
        }
      }
      if (response.ok && result.success && !form.hasAttribute("data-admin-stay")) {
        if (result.redirect_url === "/admin/index.php") {
          window.location.assign(result.redirect_url);
        } else {
          window.location.reload();
        }
      }
    } catch (error) {
      if (message) message.textContent = "The request could not be completed. Please try again.";
    } finally {
      if (button) button.disabled = false;
    }
  });
});
