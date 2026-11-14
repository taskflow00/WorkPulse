
// ==========================================
// SKILL SELECTOR
// Used by Worker Profile and Create Job
// ==========================================

document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('skill-search');
    const suggestions = document.getElementById('skill-suggestions');
    const selectedContainer = document.getElementById('selected-skills');

    // Stop if this page doesn't contain the skill selector
    if (!searchInput || !suggestions || !selectedContainer) {
        return;
    }

    // Available skills workers can choose from
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

    // Load previously selected skills
    const selectedSkills = JSON.parse(
        selectedContainer.dataset.selectedSkills || '[]'
    );

    // Display selected skill tags
    function renderSelectedSkills() {

        selectedContainer.innerHTML = '';

        selectedSkills.forEach(function (skill) {

            const tag = document.createElement('span');
            tag.className = 'skill-tag';

            const name = document.createElement('span');
            name.textContent = skill;

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = '×';

            removeButton.addEventListener('click', function () {

                const index = selectedSkills.indexOf(skill);

                if (index !== -1) {
                    selectedSkills.splice(index, 1);
                }

                renderSelectedSkills();
                renderSuggestions();
            });

            // Hidden input sends this skill to PHP
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

    // Display skills matching the search
    function renderSuggestions() {

        const search = searchInput.value.toLowerCase().trim();

        suggestions.innerHTML = '';

        const matches = availableSkills.filter(function (skill) {

            return skill.toLowerCase().includes(search)
                && !selectedSkills.includes(skill);

        }).slice(0, 8);

        matches.forEach(function (skill) {

            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = '+ ' + skill;

            button.addEventListener('click', function () {

                selectedSkills.push(skill);

                searchInput.value = '';

                renderSelectedSkills();
                renderSuggestions();
            });

            suggestions.appendChild(button);
        });
    }

    // Update suggestions whenever the user types
    searchInput.addEventListener('input', renderSuggestions);

    // Initial display
    renderSelectedSkills();
    renderSuggestions();

});


// ==========================================
// JOB LISTINGS - SEARCH AND FILTERS
// Used by the Public Job Listings page
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

    // Stop if this page doesn't contain the required filter elements
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

    // Filter the available job listings
    function filterJobs() {

        // Get current filter values
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

        // Check each job listing
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

            // Display the job only when all filters match
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

        // Update the job count
        jobCount.textContent = visibleJobs + ' job(s) available';

        // Show a message when no jobs match
        noMatchingJobs.style.display =
            visibleJobs === 0 ? 'block' : 'none';
    }

    // Run filtering whenever an input changes
    searchInput.addEventListener('input', filterJobs);
    employmentFilter.addEventListener('change', filterJobs);
    minimumRate.addEventListener('input', filterJobs);
    maximumRate.addEventListener('input', filterJobs);
    skillFilter.addEventListener('input', filterJobs);

    // Reset all filters
    clearButton.addEventListener('click', function () {

        searchInput.value = '';
        employmentFilter.value = '';
        minimumRate.value = '';
        maximumRate.value = '';
        skillFilter.value = '';

        filterJobs();
    });

    // Apply filters when the page first loads
    filterJobs();

});
