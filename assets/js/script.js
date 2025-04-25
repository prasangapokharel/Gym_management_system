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
  
  // Form validation
  document.addEventListener("DOMContentLoaded", () => {
    const forms = document.querySelectorAll("form")
  
    forms.forEach((form) => {
      form.addEventListener("submit", (event) => {
        const passwordField = form.querySelector('input[name="password"]')
        const confirmPasswordField = form.querySelector('input[name="confirm_password"]')
  
        if (passwordField && confirmPasswordField) {
          if (passwordField.value !== confirmPasswordField.value) {
            event.preventDefault()
            alert("Passwords do not match!")
          }
        }
      })
    })
  })
  
  