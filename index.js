// Additional JavaScript functions to add to your index.js file

// Period-wise Attendance Functions
function loadPeriodAttendance() {
  const date = document.getElementById("periodDate").value;
  const department = document.getElementById("periodDepartment").value;
  const section = document.getElementById("periodSection").value;

  if (!date || !department || !section) {
    alert("Please select date, department, and section");
    return;
  }

  const tableBody = document.getElementById("periodAttendanceTable");
  tableBody.innerHTML =
    '<tr><td colspan="13" class="no-data"><div class="loading"></div> Loading...</td></tr>';

  fetch(
    `get_period_attendance.php?date=${encodeURIComponent(
      date
    )}&department=${encodeURIComponent(
      department
    )}&section=${encodeURIComponent(section)}`
  )
    .then((response) => {
      if (!response.ok) throw new Error("Failed to load period attendance");
      return response.json();
    })
    .then((data) => {
      tableBody.innerHTML = "";

      if (data.error) {
        tableBody.innerHTML = `<tr><td colspan="13" class="no-data">${data.error}</td></tr>`;
        return;
      }

      data.forEach((row) => {
        const tr = document.createElement("tr");
        const dailyPercentage = calculateDailyPercentage(row.periods);

        tr.innerHTML = `
                    <td>${row.roll_no}</td>
                    <td>${row.name}</td>
                    ${Array.from({ length: 9 }, (_, i) => {
                      const period = i + 1;
                      const status = row.periods[`p${period}`] || "A";
                      const statusClass = getPeriodStatusClass(status);
                      return `<td onclick="togglePeriodStatus(${row.id}, '${date}', ${period}, '${status}')" style="cursor: pointer;">
                            <span class="status-badge ${statusClass}">${status}</span>
                        </td>`;
                    }).join("")}
                    <td>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${dailyPercentage}%"></div>
                        </div>
                        ${dailyPercentage}%
                    </td>
                    <td>
                        <button class="btn btn-warning" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;" onclick="markStudentOnDutyPeriod('${
                          row.roll_no
                        }', '${date}')">
                            Mark OD
                        </button>
                    </td>
                `;
        tableBody.appendChild(tr);
      });
    })
    .catch((error) => {
      console.error("Error loading period attendance:", error);
      tableBody.innerHTML =
        '<tr><td colspan="13" class="no-data">Failed to load period attendance data</td></tr>';
    });
}

// Calculate daily percentage from periods
function calculateDailyPercentage(periods) {
  const totalPeriods = 9;
  let presentCount = 0;

  for (let i = 1; i <= totalPeriods; i++) {
    const status = periods[`p${i}`];
    if (status === "P" || status === "OD") {
      presentCount++;
    }
  }

  return Math.round((presentCount / totalPeriods) * 100);
}

// Get CSS class for period status
function getPeriodStatusClass(status) {
  switch (status) {
    case "P":
      return "status-present";
    case "OD":
      return "status-onduty";
    case "H":
      return "status-holiday";
    default:
      return "status-absent";
  }
}

// Toggle individual period status
function togglePeriodStatus(studentId, date, period, currentStatus) {
  const nextStatus = getNextPeriodStatus(currentStatus);

  fetch("mark_period_attendance.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `student_id=${studentId}&date=${encodeURIComponent(
      date
    )}&period=${period}&status=${nextStatus}`,
  })
    .then((response) => {
      if (!response.ok) throw new Error("Failed to update period status");
      return response.json();
    })
    .then((data) => {
      if (data.status === "Success") {
        loadPeriodAttendance(); // Reload to show changes
      } else {
        alert("Error: " + (data.error || "Unknown error"));
      }
    })
    .catch((error) => {
      console.error("Error updating period status:", error);
      alert("Failed to update period status");
    });
}

// Get next status in cycle (A -> P -> OD -> A)
function getNextPeriodStatus(currentStatus) {
  switch (currentStatus) {
    case "A":
      return "Present";
    case "P":
      return "On Duty";
    case "OD":
      return "Absent";
    default:
      return "Present";
  }
}

// Mark student on duty for all periods
function markStudentOnDutyPeriod(rollNo, date) {
  fetch("mark_period_onduty.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `roll_no=${encodeURIComponent(rollNo)}&date=${encodeURIComponent(
      date
    )}`,
  })
    .then((response) => {
      if (!response.ok) throw new Error("Failed to mark On Duty");
      return response.json();
    })
    .then((data) => {
      if (data.status === "Success") {
        alert("All periods marked as On Duty");
        loadPeriodAttendance();
      } else {
        alert("Error: " + (data.error || "Unknown error"));
      }
    })
    .catch((error) => {
      console.error("Error marking On Duty:", error);
      alert("Failed to mark On Duty");
    });
}

// Mark all students present for selected periods
function markAllPresent() {
  const date = document.getElementById("periodDate").value;
  const department = document.getElementById("periodDepartment").value;
  const section = document.getElementById("periodSection").value;
  const selectedPeriods = getSelectedPeriods();

  if (!date || !department || !section) {
    alert("Please select date, department, and section");
    return;
  }

  if (selectedPeriods.length === 0) {
    alert("Please select at least one period");
    return;
  }

  if (
    !confirm(
      `Mark all students present for period(s) ${selectedPeriods.join(", ")}?`
    )
  ) {
    return;
  }

  Promise.all(
    selectedPeriods.map((period) =>
      fetch("bulk_mark_period_attendance.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `date=${encodeURIComponent(date)}&department=${encodeURIComponent(
          department
        )}&section=${encodeURIComponent(
          section
        )}&period=${period}&status=Present`,
      }).then((response) => response.json())
    )
  )
    .then((results) => {
      const allSuccessful = results.every(
        (result) => result.status === "Success"
      );
      if (allSuccessful) {
        alert("All students marked present for selected periods");
        loadPeriodAttendance();
      } else {
        alert("Some operations failed. Please check and try again.");
      }
    })
    .catch((error) => {
      console.error("Error marking all present:", error);
      alert("Failed to mark all present");
    });
}

// Mark all students absent for selected periods
function markAllAbsent() {
  const date = document.getElementById("periodDate").value;
  const department = document.getElementById("periodDepartment").value;
  const section = document.getElementById("periodSection").value;
  const selectedPeriods = getSelectedPeriods();

  if (!date || !department || !section) {
    alert("Please select date, department, and section");
    return;
  }

  if (selectedPeriods.length === 0) {
    alert("Please select at least one period");
    return;
  }

  if (
    !confirm(
      `Mark all students absent for period(s) ${selectedPeriods.join(", ")}?`
    )
  ) {
    return;
  }

  Promise.all(
    selectedPeriods.map((period) =>
      fetch("bulk_mark_period_attendance.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `date=${encodeURIComponent(date)}&department=${encodeURIComponent(
          department
        )}&section=${encodeURIComponent(
          section
        )}&period=${period}&status=Absent`,
      }).then((response) => response.json())
    )
  )
    .then((results) => {
      const allSuccessful = results.every(
        (result) => result.status === "Success"
      );
      if (allSuccessful) {
        alert("All students marked absent for selected periods");
        loadPeriodAttendance();
      } else {
        alert("Some operations failed. Please check and try again.");
      }
    })
    .catch((error) => {
      console.error("Error marking all absent:", error);
      alert("Failed to mark all absent");
    });
}

// Get selected periods from UI
function getSelectedPeriods() {
  const selectedButtons = document.querySelectorAll(".period-btn.active");
  return Array.from(selectedButtons).map((btn) => btn.dataset.period);
}

// Subject-wise Attendance Functions
function loadSubjectSummary() {
  const rollNo = document.getElementById("subjectRoll").value.trim();
  const month = document.getElementById("subjectMonth").value;
  const tableBody = document.getElementById("subjectSummaryTable");

  if (!rollNo || !month) {
    alert("Please enter roll number and select month");
    tableBody.innerHTML =
      '<tr><td colspan="6" class="no-data">Please enter roll number and select month</td></tr>';
    return;
  }

  tableBody.innerHTML =
    '<tr><td colspan="6" class="no-data"><div class="loading"></div> Loading...</td></tr>';

  fetch(
    `get_subject_summary.php?roll_no=${encodeURIComponent(
      rollNo
    )}&month=${encodeURIComponent(month)}`
  )
    .then((response) => {
      if (!response.ok) throw new Error("Failed to load subject summary");
      return response.json();
    })
    .then((data) => {
      tableBody.innerHTML = "";

      if (data.error) {
        tableBody.innerHTML = `<tr><td colspan="6" class="no-data">${data.error}</td></tr>`;
        alert(data.error);
        return;
      }

      if (data.length === 0) {
        tableBody.innerHTML =
          '<tr><td colspan="6" class="no-data">No subject data found for this student and month</td></tr>';
        return;
      }

      data.forEach((subject) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
                    <td class="border px-4 py-2">${subject.subject_name}</td>
                    <td class="border px-4 py-2">${subject.total_classes}</td>
                    <td class="border px-4 py-2 text-green-600 font-bold">${subject.present}</td>
                    <td class="border px-4 py-2 text-red-500 font-bold">${subject.absent}</td>
                    <td class="border px-4 py-2 text-yellow-500 font-bold">${subject.onduty}</td>
                    <td class="border px-4 py-2">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${subject.percentage}%"></div>
                        </div>
                        ${subject.percentage}%
                    </td>
                `;
        tableBody.appendChild(tr);
      });
    })
    .catch((error) => {
      console.error("Error loading subject summary:", error);
      tableBody.innerHTML =
        '<tr><td colspan="6" class="no-data">Failed to load subject summary data</td></tr>';
      alert("Failed to load subject summary: " + error.message);
    });
}

// Enhanced Monthly Summary Function (extends existing one)
function loadMonthlySummary() {
  const rollNo = document.getElementById("monthlyRoll").value.trim();
  const month = document.getElementById("monthlyDate").value;
  const tableBody = document.getElementById("monthlySummaryTable");

  if (!rollNo || !month) {
    alert("Please enter a roll number and select a month");
    tableBody.innerHTML = `<tr><td colspan="7" class="no-data">Please enter roll number and select month</td></tr>`;
    return;
  }

  tableBody.innerHTML = `<tr><td colspan="7" class="no-data"><div class="loading"></div> Loading...</td></tr>`;

  fetch(
    `get_student_monthly_summary.php?roll_no=${encodeURIComponent(
      rollNo
    )}&month=${encodeURIComponent(month)}`
  )
    .then((response) => {
      if (!response.ok)
        throw new Error(`HTTP error! status: ${response.status}`);
      return response.json();
    })
    .then((data) => {
      tableBody.innerHTML = "";

      if (data.error) {
        tableBody.innerHTML = `<tr><td colspan="7" class="no-data">${data.error}</td></tr>`;
        alert(data.error);
        return;
      }

      const tr = document.createElement("tr");
      tr.innerHTML = `
                <td class="border px-4 py-2">${data.name}</td>
                <td class="border px-4 py-2">${data.roll_no}</td>
                <td class="border px-4 py-2"><span class="status-badge status-present">${data.present_count}</span></td>
                <td class="border px-4 py-2"><span class="status-badge status-absent">${data.absent_count}</span></td>
                <td class="border px-4 py-2"><span class="status-badge status-onduty">${data.onduty_count}</span></td>
                <td class="border px-4 py-2"><span class="status-badge status-holiday">${data.holiday_count}</span></td>
                <td class="border px-4 py-2">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${data.percent}%"></div>
                    </div>
                    ${data.percent}%
                </td>
            `;
      tableBody.appendChild(tr);
    })
    .catch((error) => {
      console.error("Error loading monthly summary:", error);
      tableBody.innerHTML = `<tr><td colspan="7" class="no-data">Failed to load monthly summary: ${error.message}</td></tr>`;
      alert("Failed to load monthly summary: " + error.message);
    });
}

// Manual attendance trigger function
function triggerAttendanceUpdate() {
  const date = new Date().toISOString().split("T")[0]; // Today's date

  if (
    !confirm(
      "Trigger attendance update for today? This will process all captured MAC addresses."
    )
  ) {
    return;
  }

  fetch("trigger_attendance_update.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `date=${date}`,
  })
    .then((response) => {
      if (!response.ok) throw new Error("Failed to trigger attendance update");
      return response.json();
    })
    .then((data) => {
      if (data.status === "Success") {
        alert("Attendance update triggered successfully");
      } else {
        alert("Error: " + (data.error || "Unknown error"));
      }
    })
    .catch((error) => {
      console.error("Error triggering attendance update:", error);
      alert("Failed to trigger attendance update");
    });
}

// Utility function to format time periods
function formatPeriodTime(period) {
  const times = {
    1: "8:30-9:15",
    2: "9:15-10:00",
    3: "10:00-10:45",
    4: "11:05-11:50",
    5: "11:50-12:35",
    6: "1:20-2:05",
    7: "2:05-2:50",
    8: "3:05-3:50",
    9: "3:50-4:35",
  };
  return times[period] || "Unknown";
}

// Initialize period buttons functionality
document.addEventListener("DOMContentLoaded", function () {
  // Set today's date as default for new inputs
  const today = new Date().toISOString().split("T")[0];

  // Set dates if elements exist
  if (document.getElementById("periodDate")) {
    document.getElementById("periodDate").value = today;
  }

  // Set current month for monthly inputs
  const currentMonth = new Date().toISOString().slice(0, 7);
  if (document.getElementById("subjectMonth")) {
    document.getElementById("subjectMonth").value = currentMonth;
  }

  // Initialize period button handlers
  const periodButtons = document.querySelectorAll(".period-btn");
  periodButtons.forEach((btn) => {
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      this.classList.toggle("active");
    });
  });
});
