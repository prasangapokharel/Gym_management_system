// Toggle sidebar on mobile
document.addEventListener("DOMContentLoaded", () => {
    const sidebarToggle = document.querySelector(".navbar-toggler")
  
    if (sidebarToggle) {
      sidebarToggle.addEventListener("click", () => {
        document.querySelector(".sidebar").classList.toggle("show")
      })
    }
  
    // Close sidebar when clicking outside on mobile
    document.addEventListener("click", (event) => {
      const sidebar = document.querySelector(".sidebar")
      const sidebarToggle = document.querySelector(".navbar-toggler")
  
      if (sidebar && sidebarToggle) {
        if (
          !sidebar.contains(event.target) &&
          !sidebarToggle.contains(event.target) &&
          sidebar.classList.contains("show")
        ) {
          sidebar.classList.remove("show")
        }
      }
    })
  })
  
  // Confirm delete actions
  document.addEventListener("DOMContentLoaded", () => {
    const deleteForms = document.querySelectorAll('form[onsubmit*="confirm"]')
  
    deleteForms.forEach((form) => {
      form.addEventListener("submit", function (event) {
        const confirmMessage = this.getAttribute("onsubmit").match(/'([^']+)'/)[1]
        if (!confirm(confirmMessage)) {
          event.preventDefault()
        }
      })
    })
  })
  
  // Password visibility toggle
  document.addEventListener("DOMContentLoaded", () => {
    const passwordFields = document.querySelectorAll('input[type="password"]')
  
    passwordFields.forEach((field) => {
      // Create toggle button
      const toggleButton = document.createElement("button")
      toggleButton.type = "button"
      toggleButton.className = "btn btn-sm btn-outline-secondary password-toggle"
      toggleButton.innerHTML = '<i class="bi bi-eye"></i>'
      toggleButton.style.position = "absolute"
      toggleButton.style.right = "10px"
      toggleButton.style.top = "50%"
      toggleButton.style.transform = "translateY(-50%)"
      toggleButton.style.zIndex = "10"
  
      // Wrap password field in a relative positioned div
      const wrapper = document.createElement("div")
      wrapper.style.position = "relative"
      field.parentNode.insertBefore(wrapper, field)
      wrapper.appendChild(field)
      wrapper.appendChild(toggleButton)
  
      // Add toggle functionality
      toggleButton.addEventListener("click", function () {
        if (field.type === "password") {
          field.type = "text"
          this.innerHTML = '<i class="bi bi-eye-slash"></i>'
        } else {
          field.type = "password"
          this.innerHTML = '<i class="bi bi-eye"></i>'
        }
      })
    })
  })
  
  