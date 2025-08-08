document.addEventListener('DOMContentLoaded', () => {
    const genreFilter = document.getElementById('books-page-genre');
    const authorFilter = document.getElementById('books-page-author');
    const books = document.querySelectorAll('.books-page-card');

    function filterBooks() {
        const selectedGenre = genreFilter.value;
        const selectedAuthor = authorFilter.value;

        books.forEach(book => {
            const matchesGenre = (selectedGenre === 'all' || book.dataset.genre === selectedGenre);
            const matchesAuthor = (selectedAuthor === 'all' || book.dataset.author === selectedAuthor);

            if (matchesGenre && matchesAuthor) {
                book.style.display = '';
            } else {
                book.style.display = 'none';
            }
        });
    }

    genreFilter.addEventListener('change', filterBooks);
    authorFilter.addEventListener('change', filterBooks);
});
