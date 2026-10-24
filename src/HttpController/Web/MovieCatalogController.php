<?php declare(strict_types=1);

namespace Movary\HttpController\Web;

use Movary\Api\Tmdb\TmdbApi;
use Movary\Api\Tmdb\TmdbUrlGenerator;
use Movary\Domain\Movie\History\MovieHistoryApi;
use Movary\Domain\Movie\MovieApi;
use Movary\Domain\Movie\Watchlist\MovieWatchlistApi;
use Movary\Domain\User\Service\Authentication;
use Movary\Service\PaginationElementsCalculator;
use Movary\ValueObject\Http\Request;
use Movary\ValueObject\Http\Response;
use Movary\ValueObject\Http\StatusCode;
use Twig\Environment;

class MovieCatalogController
{
    private const array GENRES = [
        28 => 'Action', 12 => 'Adventure', 16 => 'Animation', 35 => 'Comedy',
        80 => 'Crime', 99 => 'Documentary', 18 => 'Drama', 10751 => 'Family',
        14 => 'Fantasy', 36 => 'History', 27 => 'Horror', 10402 => 'Music',
        9648 => 'Mystery', 10749 => 'Romance', 878 => 'Sci-Fi', 53 => 'Thriller',
        10752 => 'War', 37 => 'Western',
    ];

    private const array LANGUAGES = [
        'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
        'it' => 'Italian', 'ja' => 'Japanese', 'ko' => 'Korean', 'zh' => 'Chinese',
        'hi' => 'Hindi', 'pt' => 'Portuguese', 'ru' => 'Russian', 'ar' => 'Arabic',
        'tr' => 'Turkish', 'sv' => 'Swedish', 'nl' => 'Dutch', 'pl' => 'Polish',
        'th' => 'Thai', 'da' => 'Danish', 'fi' => 'Finnish', 'nb' => 'Norwegian',
    ];

    private const array ALLOWED_SORT = [
        'popularity.desc', 'release_date.asc', 'release_date.desc',
        'original_title.asc', 'vote_average.desc',
    ];

    private const array RUNTIME_RANGES = [
        'short'  => ['lte' => '90'],
        'medium' => ['gte' => '90', 'lte' => '120'],
        'long'   => ['gte' => '120', 'lte' => '150'],
        'epic'   => ['gte' => '150'],
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly TmdbApi $tmdbApi,
        private readonly TmdbUrlGenerator $tmdbUrlGenerator,
        private readonly MovieApi $movieApi,
        private readonly MovieHistoryApi $movieHistoryApi,
        private readonly MovieWatchlistApi $movieWatchlistApi,
        private readonly PaginationElementsCalculator $paginationElementsCalculator,
        private readonly Authentication $authenticationService,
    ) {
    }

    public function renderPage(Request $request) : Response
    {
        $userId = $this->authenticationService->requireWebSession()->getUserId();
        $params = $request->getGetParameters();

        $page = max(1, (int)($params['p'] ?? 1));
        $sortBy = in_array($params['sort'] ?? '', self::ALLOWED_SORT, true) ? $params['sort'] : 'popularity.desc';

        $year = isset($params['year']) && $params['year'] !== '' ? (int)$params['year'] : null;
        $genreId = isset($params['genre']) && $params['genre'] !== '' ? (int)$params['genre'] : null;
        $language = isset($params['lang']) && array_key_exists($params['lang'], self::LANGUAGES) ? $params['lang'] : null;
        $minRating = isset($params['minRating']) && in_array((int)$params['minRating'], [6, 7, 8, 9], true) ? (int)$params['minRating'] : null;
        $runtime = isset($params['runtime']) && array_key_exists($params['runtime'], self::RUNTIME_RANGES) ? $params['runtime'] : null;

        $actorName = isset($params['actor']) && $params['actor'] !== '' ? trim($params['actor']) : null;
        $actorId = isset($params['actorId']) && $params['actorId'] !== '' ? (int)$params['actorId'] : null;
        if ($actorName !== null && $actorId === null) {
            $hit = ($this->tmdbApi->searchPerson($actorName)['results'] ?? [])[0] ?? null;
            $actorId = $hit !== null ? (int)$hit['id'] : null;
            $actorName = $hit !== null ? $hit['name'] : $actorName;
        }

        $directorName = isset($params['director']) && $params['director'] !== '' ? trim($params['director']) : null;
        $directorId = isset($params['directorId']) && $params['directorId'] !== '' ? (int)$params['directorId'] : null;
        if ($directorName !== null && $directorId === null) {
            $hit = ($this->tmdbApi->searchPerson($directorName)['results'] ?? [])[0] ?? null;
            $directorId = $hit !== null ? (int)$hit['id'] : null;
            $directorName = $hit !== null ? $hit['name'] : $directorName;
        }

        $filters = [];
        if ($year !== null) {
            $filters['primary_release_year'] = (string)$year;
        }
        if ($genreId !== null) {
            $filters['with_genres'] = (string)$genreId;
        }
        if ($actorId !== null) {
            $filters['with_cast'] = (string)$actorId;
        }
        if ($directorId !== null) {
            $filters['with_crew'] = (string)$directorId;
        }
        if ($language !== null) {
            $filters['with_original_language'] = $language;
        }
        if ($minRating !== null) {
            $filters['vote_average.gte'] = (string)$minRating;
            $filters['vote_count.gte'] = '50';
        }
        if ($runtime !== null) {
            foreach (self::RUNTIME_RANGES[$runtime] as $bound => $value) {
                $filters['with_runtime.' . $bound] = $value;
            }
        }

        $tmdbResponse = !empty($filters)
            ? $this->tmdbApi->discoverMovies($filters, $sortBy, $page)
            : $this->tmdbApi->fetchPopularMovies($page);

        $totalResults = (int)($tmdbResponse['total_results'] ?? 0);
        $totalPages = (int)($tmdbResponse['total_pages'] ?? 1);
        $perPage = $totalPages > 0 ? (int)ceil($totalResults / $totalPages) : 20;

        $paginationElements = $this->paginationElementsCalculator->createPaginationElements($totalResults, $perPage, $page);

        $tmdbIds = array_column($tmdbResponse['results'] ?? [], 'id');
        $watchedMap = $this->movieHistoryApi->fetchTmdbIdsToLastWatchDatesMap($userId, $tmdbIds);
        $watchlistMap = $this->movieWatchlistApi->fetchTmdbIdsToWatchlistMap($userId, $tmdbIds);
        $movaryIdMap = [];
        foreach ($this->movieApi->findByTmdbIds($tmdbIds) as $movie) {
            $movaryIdMap[$movie->getTmdbId()] = $movie->getId();
        }

        $movies = [];
        foreach ($tmdbResponse['results'] ?? [] as $result) {
            $posterPath = $result['poster_path'] ?? null;
            $tmdbId = $result['id'];
            $movies[] = [
                'tmdbId' => $tmdbId,
                'movaryId' => $movaryIdMap[$tmdbId] ?? null,
                'title' => $result['title'],
                'originalTitle' => $result['original_title'],
                'releaseYear' => empty($result['release_date']) ? null : substr($result['release_date'], 0, 4),
                'posterUrl' => $posterPath !== null
                    ? (string)$this->tmdbUrlGenerator->generateImageUrl($posterPath, 'w342')
                    : null,
                'isWatched' => isset($watchedMap[$tmdbId]),
                'isOnWatchlist' => isset($watchlistMap[$tmdbId]),
            ];
        }

        return Response::create(
            StatusCode::createOk(),
            $this->twig->render('page/movie-catalog.html.twig', [
                'movies' => $movies,
                'paginationElements' => $paginationElements,
                'totalResults' => $totalResults,
                'genres' => self::GENRES,
                'languages' => self::LANGUAGES,
                'currentYear' => (int)date('Y'),
                'selectedYear' => $year,
                'selectedGenre' => $genreId,
                'selectedLanguage' => $language,
                'minRating' => $minRating,
                'runtime' => $runtime,
                'actorName' => $actorName,
                'actorId' => $actorId,
                'directorName' => $directorName,
                'directorId' => $directorId,
                'sortBy' => $sortBy,
            ]),
        );
    }
}
