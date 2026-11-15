
// ==========================================
// SKILL SELECTOR
// Used by Worker Profile and Create Job
// ==========================================

document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('skill-search');
    const suggestions = document.getElementById('skill-suggestions');
    const selectedContainer = document.getElementById('selected-skills');

    // Stop if this page doesn't contain the skill selector.
    if (!searchInput || !suggestions || !selectedContainer) {
        return;
    }

    // Available skills.
    const availableSkills = [
        'HTML', 'CSS', 'JavaScript', 'PHP', 'Python',
        'Java', 'C#', 'C++', 'Node.js', 'React',
        'Vue.js', 'Laravel', 'MySQL', 'PostgreSQL',
        'Supabase', 'WordPress', 'Git', 'UI/UX Design',
        'Graphic Design', 'Adobe Photoshop', 'Adobe Illustrator',
        'Video Editing', 'Content Writing', 'Copywriting',
        'SEO', 'Social Media Management', 'Digital Marketing',
        'Customer Support', 'Virtual Assistance',
        'Data Entry', 'Microsoft Excel', 'Microsoft Word',
        'Project Management', 'Bookkeeping', 'Email Management'
    ];

    // Load previously selected skills.
    const selectedSkills = JSON.parse(
        selectedContainer.dataset.selectedSkills || '[]'
    );


    // ==========================================
    // DISPLAY SELECTED SKILLS
    // ==========================================

    function renderSelectedSkills() {

        selectedContainer.innerHTML = '';

        selectedSkills.forEach(function (skill) {

            const tag = document.createElement('span');
            tag.className = 'skill-tag';

            // Skill name.
            const name = document.createElement('span');
            name.textContent = skill;

            // Remove button.
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = '×';
            removeButton.setAttribute('aria-label', 'Remove ' + skill);

            removeButton.addEventListener('click', function () {

                const index = selectedSkills.indexOf(skill);

                if (index !== -1) {
                    selectedSkills.splice(index, 1);
                }

                renderSelectedSkills();
                renderSuggestions();
            });

            // Hidden input sends the skill to PHP.
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = selectedContainer.dataset.inputName || 'skills[]';
            hiddenInput.value = skill;

            tag.appendChild(name);
            tag.appendChild(removeButton);
            tag.appendChild(hiddenInput);

            selectedContainer.appendChild(tag);
        });
    }


    // ==========================================
    // DISPLAY SEARCH SUGGESTIONS
    // ==========================================

    function renderSuggestions() {

        const search = searchInput.value.toLowerCase().trim();

        suggestions.innerHTML = '';

        // Hide suggestions if the search is empty.
        if (search === '') {
            suggestions.classList.remove('is-open');
            return;
        }

        // Find matching skills.
        const matches = availableSkills.filter(function (skill) {

            return skill.toLowerCase().includes(search)
                && !selectedSkills.includes(skill);

        }).slice(0, 8);


        // Show a message if no skills match.
        if (matches.length === 0) {

            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'skill-no-results';
            emptyMessage.textContent = 'No matching skills found.';

            suggestions.appendChild(emptyMessage);

        } else {

            // Create a button for every matching skill.
            matches.forEach(function (skill) {

                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = '+  ' + skill;

                button.addEventListener('click', function () {

                    selectedSkills.push(skill);

                    // Clear the search after selection.
                    searchInput.value = '';

                    renderSelectedSkills();
                    renderSuggestions();

                    searchInput.focus();
                });

                suggestions.appendChild(button);
            });
        }

        suggestions.classList.add('is-open');
    }


    // ==========================================
    // EVENT LISTENERS
    // ==========================================

    // Search while typing.
    searchInput.addEventListener('input', renderSuggestions);

    // Close dropdown when clicking outside.
    document.addEventListener('click', function (event) {

        if (
            !searchInput.contains(event.target) &&
            !suggestions.contains(event.target)
        ) {
            suggestions.classList.remove('is-open');
        }
    });

    // Initial display.
    renderSelectedSkills();

});


// ==========================================
// JOB LISTINGS - SEARCH AND FILTERS
// ==========================================

document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('job-search');
    const employmentFilter = document.getElementById('employment-filter');
    const minimumRate = document.getElementById('minimum-rate');
    const maximumRate = document.getElementById('maximum-rate');
    const skillFilter = document.getElementById('skill-filter');
    const clearButton = document.getElementById('clear-filters');

    const jobCards = document.querySelectorAll('.job-card');
    const jobCount = document.getElementById('job-count');
    const noMatchingJobs = document.getElementById('no-matching-jobs');

    if (
        !searchInput ||
        !employmentFilter ||
        !minimumRate ||
        !maximumRate ||
        !skillFilter ||
        !clearButton ||
        !jobCount ||
        !noMatchingJobs
    ) {
        return;
    }

    function filterJobs() {

        const search = searchInput.value.toLowerCase().trim();
        const employmentType = employmentFilter.value;

        const minRate = minimumRate.value === ''
            ? null
            : Number(minimumRate.value);

        const maxRate = maximumRate.value === ''
            ? null
            : Number(maximumRate.value);

        const skill = skillFilter.value.toLowerCase().trim();

        let visibleJobs = 0;

        jobCards.forEach(function (card) {

            const jobText = card.dataset.search || '';
            const jobType = card.dataset.type || '';
            const jobRate = Number(card.dataset.rate || 0);
            const jobSkills = card.dataset.skills || '';

            const matchesSearch = jobText.includes(search);

            const matchesType =
                employmentType === '' || jobType === employmentType;

            const matchesMinimum =
                minRate === null || jobRate >= minRate;

            const matchesMaximum =
                maxRate === null || jobRate <= maxRate;

            const matchesSkill =
                skill === '' || jobSkills.includes(skill);

            if (
                matchesSearch &&
                matchesType &&
                matchesMinimum &&
                matchesMaximum &&
                matchesSkill
            ) {

                card.style.display = '';
                visibleJobs++;

            } else {

                card.style.display = 'none';
            }
        });

        jobCount.textContent = visibleJobs + ' job(s) available';

        noMatchingJobs.style.display =
            visibleJobs === 0 ? 'block' : 'none';
    }

    searchInput.addEventListener('input', filterJobs);
    employmentFilter.addEventListener('change', filterJobs);
    minimumRate.addEventListener('input', filterJobs);
    maximumRate.addEventListener('input', filterJobs);
    skillFilter.addEventListener('input', filterJobs);

    clearButton.addEventListener('click', function () {

        searchInput.value = '';
        employmentFilter.value = '';
        minimumRate.value = '';
        maximumRate.value = '';
        skillFilter.value = '';

        filterJobs();
    });

    filterJobs();

});


// ==========================================
// WORKPULSE - DASHBOARD SIDEBAR
// Mobile navigation toggle
// ==========================================

document.addEventListener('DOMContentLoaded', function () {

    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    if (!sidebar || !sidebarToggle || !sidebarBackdrop) {
        return;
    }

    // Open or close the sidebar.
    sidebarToggle.addEventListener('click', function () {

        sidebar.classList.toggle('show');
        sidebarBackdrop.classList.toggle('show');

    });

    // Close sidebar when clicking outside.
    sidebarBackdrop.addEventListener('click', function () {

        sidebar.classList.remove('show');
        sidebarBackdrop.classList.remove('show');

    });

});
