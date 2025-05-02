// Global AJAX setup for CSRF token
function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || ""
}

// Initialize alertify (assuming it's a library you're using)
// If you're using a module bundler, import it here:
// import alertify from 'alertifyjs';
// Otherwise, ensure it's included in your HTML before this script.
if (typeof alertify === "undefined") {
  console.warn("alertify is not defined. Ensure it is properly included in your project.")
  // Declare alertify as an empty object to prevent errors if it's not included.
  window.alertify = {}
  alertify.error = (message) => {
    console.error(message)
  }
  alertify.success = (message) => {
    console.log(message)
  }
}

// Geolocation functions
function getLocation(callback) {
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      (position) => {
        callback({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          success: true,
        })
      },
      (error) => {
        let errorMessage
        switch (error.code) {
          case error.PERMISSION_DENIED:
            errorMessage = "User denied the request for Geolocation."
            break
          case error.POSITION_UNAVAILABLE:
            errorMessage = "Location information is unavailable."
            break
          case error.TIMEOUT:
            errorMessage = "The request to get user location timed out."
            break
          case error.UNKNOWN_ERROR:
            errorMessage = "An unknown error occurred."
            break
        }
        if (typeof alertify !== "undefined") {
          alertify.error(errorMessage)
        }
        callback({ success: false, error: errorMessage })
      },
    )
  } else {
    if (typeof alertify !== "undefined") {
      alertify.error("Geolocation is not supported by this browser.")
    }
    callback({ success: false, error: "Geolocation not supported" })
  }
}

// OTP Timer
function startOtpTimer(duration, display) {
  let timer = duration
  let minutes, seconds

  const interval = setInterval(() => {
    minutes = Number.parseInt(timer / 60, 10)
    seconds = Number.parseInt(timer % 60, 10)

    minutes = minutes < 10 ? "0" + minutes : minutes
    seconds = seconds < 10 ? "0" + seconds : seconds

    display.textContent = minutes + ":" + seconds

    if (--timer < 0) {
      clearInterval(interval)
      display.textContent = "Expired"
      document.getElementById("otpDisplay")?.classList.add("text-muted")
      document.getElementById("generateOtpBtn")?.removeAttribute("disabled")
      if (typeof alertify !== "undefined") {
        alertify.error("OTP has expired")
      }
    }
  }, 1000)

  return interval
}

// Linear search implementation in JavaScript
function linearSearch(arr, key, value) {
  const results = []

  for (let i = 0; i < arr.length; i++) {
    if (arr[i][key] === value) {
      results.push(arr[i])
    }
  }

  return results
}

// Form validations
document.addEventListener("DOMContentLoaded", () => {
  // Email validation
  const emailInputs = document.querySelectorAll('input[type="email"]')
  emailInputs.forEach((input) => {
    input.addEventListener("blur", function () {
      const emailRegex = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/
      if (this.value && !emailRegex.test(this.value)) {
        if (typeof alertify !== "undefined") {
          alertify.error("Please enter a valid email address")
        }
        this.focus()
      }
    })
  })

  // Phone validation
  const phoneInputs = document.querySelectorAll('input[id="phone"]')
  phoneInputs.forEach((input) => {
    input.addEventListener("input", function () {
      this.value = this.value.replace(/[^0-9]/g, "")
      if (this.value.length > 10) {
        this.value = this.value.slice(0, 10)
      }
    })

    input.addEventListener("blur", function () {
      if (this.value && this.value.length !== 10) {
        if (typeof alertify !== "undefined") {
          alertify.error("Phone number must be 10 digits")
        }
        this.focus()
      }
    })
  })
})

// Calendar functionality
function renderCalendar(month, year, attendanceData, holidays) {
  const calendar = document.getElementById("calendar")
  if (!calendar) return

  // Clear previous calendar
  calendar.innerHTML = ""

  // Month and year controls
  const header = document.createElement("div")
  header.className = "calendar-header d-flex justify-content-between align-items-center mb-3"
  header.innerHTML = `
        <button class="btn btn-sm btn-outline-danger prev-month">&lt; Prev</button>
        <h4 class="month-year mb-0">${new Date(year, month).toLocaleString("default", { month: "long" })} ${year}</h4>
        <button class="btn btn-sm btn-outline-danger next-month">Next &gt;</button>
    `
  calendar.appendChild(header)

  // Days of week header
  const daysOfWeek = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"]
  const daysHeader = document.createElement("div")
  daysHeader.className = "calendar-row d-flex mb-1"

  daysOfWeek.forEach((day) => {
    const dayHeader = document.createElement("div")
    dayHeader.className = "calendar-day-header"
    dayHeader.textContent = day
    daysHeader.appendChild(dayHeader)
  })

  calendar.appendChild(daysHeader)

  // Calendar body
  const calendarBody = document.createElement("div")
  calendarBody.className = "calendar-body"

  // Get first day of month and number of days
  const firstDay = new Date(year, month, 1).getDay()
  const daysInMonth = new Date(year, month + 1, 0).getDate()

  // Create calendar days
  let date = 1
  for (let i = 0; i < 6; i++) {
    // Create a calendar row
    const row = document.createElement("div")
    row.className = "calendar-row d-flex mb-1"

    // Fill in the days
    for (let j = 0; j < 7; j++) {
      const cell = document.createElement("div")
      cell.className = "calendar-day"

      if (i === 0 && j < firstDay) {
        // Empty cells before first day
        cell.innerHTML = ""
      } else if (date > daysInMonth) {
        // Empty cells after last day
        break
      } else {
        // Regular day cell
        const currentDate = `${year}-${(month + 1).toString().padStart(2, "0")}-${date.toString().padStart(2, "0")}`
        cell.innerHTML = `<div class="day-number">${date}</div>`

        // Check for holiday
        if (holidays && holidays[currentDate]) {
          cell.classList.add("attendance-holiday")
          cell.innerHTML += `<div class="small text-muted">${holidays[currentDate]}</div>`
        }

        // Check attendance status
        if (attendanceData && attendanceData[currentDate]) {
          const status = attendanceData[currentDate]
          if (status === "present") {
            cell.classList.add("attendance-present")
            cell.innerHTML += '<div class="small text-success">Present</div>'
          } else if (status === "absent") {
            cell.classList.add("attendance-absent")
            cell.innerHTML += '<div class="small text-danger">Absent</div>'
          } else if (status === "requested") {
            cell.classList.add("attendance-requested")
            cell.innerHTML += '<div class="small text-warning">Requested</div>'
          }
        }

        // Add attendance request button for student
        if (document.body.classList.contains("student-dashboard") && !holidays[currentDate]) {
          const today = new Date()
          const cellDate = new Date(year, month, date)

          // Only allow requests for past dates (not future)
          if (cellDate < today && (!attendanceData || !attendanceData[currentDate])) {
            cell.innerHTML += `
                            <button class="btn btn-sm btn-outline-warning request-attendance mt-1" 
                                    data-date="${currentDate}">
                                Request
                            </button>
                        `
          }
        }

        date++
      }

      row.appendChild(cell)
    }

    calendarBody.appendChild(row)

    // Stop if we've run out of days
    if (date > daysInMonth) {
      break
    }
  }

  calendar.appendChild(calendarBody)

  // Event listeners for prev/next month
  const prevMonthBtn = calendar.querySelector(".prev-month")
  const nextMonthBtn = calendar.querySelector(".next-month")

  prevMonthBtn.addEventListener("click", () => {
    let newMonth = month - 1
    let newYear = year

    if (newMonth < 0) {
      newMonth = 11
      newYear--
    }

    // Load attendance data for new month and re-render
    loadAttendanceData(newMonth, newYear)
  })

  nextMonthBtn.addEventListener("click", () => {
    let newMonth = month + 1
    let newYear = year

    if (newMonth > 11) {
      newMonth = 0
      newYear++
    }

    // Load attendance data for new month and re-render
    loadAttendanceData(newMonth, newYear)
  })

  // Event listeners for attendance request buttons
  const requestButtons = document.querySelectorAll(".request-attendance")
  requestButtons.forEach((button) => {
    button.addEventListener("click", function () {
      const date = this.getAttribute("data-date")
      requestAttendance(date)
    })
  })
}

// Function to request attendance
function requestAttendance(date) {
  getLocation((locationData) => {
    if (!locationData.success) {
      if (typeof alertify !== "undefined") {
        alertify.error("Location data is required to request attendance")
      }
      return
    }

    const requestData = {
      date: date,
      latitude: locationData.latitude,
      longitude: locationData.longitude,
    }

    // Send AJAX request
    fetch("attendance_request.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        "X-CSRF-Token": getCsrfToken(),
      },
      body: new URLSearchParams(requestData),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          if (typeof alertify !== "undefined") {
            alertify.success(data.message)
          }

          // Reload calendar to show the requested attendance
          const date = new Date(requestData.date)
          loadAttendanceData(date.getMonth(), date.getFullYear())
        } else {
          if (typeof alertify !== "undefined") {
            alertify.error(data.message)
          }
        }
      })
      .catch((error) => {
        if (typeof alertify !== "undefined") {
          alertify.error("Error requesting attendance: " + error.message)
        }
      })
  })
}

// Function to load attendance data for a specific month
function loadAttendanceData(month, year) {
  fetch(`get_attendance.php?month=${month + 1}&year=${year}`, {
    method: "GET",
    headers: {
      "X-CSRF-Token": getCsrfToken(),
    },
  })
    .then((response) => response.json())
    .then((data) => {
      renderCalendar(month, year, data.attendance, data.holidays)
    })
    .catch((error) => {
      if (typeof alertify !== "undefined") {
        alertify.error("Error loading attendance data: " + error.message)
      }
      renderCalendar(month, year, null, null)
    })
}

