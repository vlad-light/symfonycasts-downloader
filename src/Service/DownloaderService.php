<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\TransferStats;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

class DownloaderService {
	/**
	 * @var SymfonyStyle $io
	 */
	private $io;
	
	/**
	 * @var array $configs
	 */
	private $configs;
	
	/**
	 * @var Client $client
	 */
	private $client;
	/**
	 * @var \Symfony\Component\Filesystem\Filesystem
	 */
	private $filesystem;
	
	/**
	 * @var FileCookieJar|null
	 */
	private $cookieFile;
	
	/**
	 * App constructor.
	 *
	 * @param SymfonyStyle $io
	 * @param \Symfony\Component\Filesystem\Filesystem $filesystem
	 * @param array $configs
	 */
	public function __construct( SymfonyStyle $io, Filesystem $filesystem, array $configs ) {
		$this->io         = $io;
		$this->configs    = $configs;
		$this->filesystem = $filesystem;
		
		$this->client = new Client( [
			'base_uri' => $this->configs['URL'],
			'cookies'  => TRUE
		] );
	}
	
	/**
	 * Download courses
	 */
	public function download() {
		$this->login();
		
		$downloadPath = "{$this->configs['TARGET']}/symfonycasts";
		if ( ! $this->filesystem->exists( $downloadPath ) ) {
			$this->filesystem->mkdir( $downloadPath, 0700 );
		}
		
		if ( ! $this->filesystem->exists( $downloadPath ) ) {
			$this->io->error( "Unable to create download directory '{$downloadPath}'" );
			
			return;
		}
		
		$courses = $this->fetchCourses();
		
		dump($courses);
		
		$this->io->note( 'Fertig..' );
		
		return;
		
		$coursesCounter = 0;
		$coursesCount   = \count( $courses );
		foreach ( $courses as $title => $urls ) {
			++ $coursesCounter;
			$this->io->newLine( 3 );
			$this->io->title( "Processing course: '{$title}' ({$coursesCounter} of {$coursesCount})" );
			
			if ( empty( $urls ) ) {
				$this->io->warning( 'No chapters to download' );
				
				continue;
			}
			
			$coursePath = "{$downloadPath}/{$title}";
			if ( ! is_dir( $coursePath ) && ! mkdir( $coursePath ) && ! is_dir( $coursePath ) ) {
				$this->io->error( 'Unable to create course directory' );
				
				continue;
			}
			
			$chaptersCounter = 0;
			$chaptersCount   = \count( $urls );
			foreach ( $urls as $name => $url ) {
				++ $chaptersCounter;
				$this->io->newLine();
				$this->io->section( "Chapter '{$this->dashesToTitle($name)}' ({$chaptersCounter} of {$chaptersCount})" );
				
				try {
					$response = $this->client->get( $url );
				} catch ( ClientException $e ) {
					$this->io->error( $e->getMessage() );
					
					continue;
				}
				
				$crawler = new Crawler( $response->getBody()->getContents() );
				foreach ( $crawler->filter( '.download-buy-buttons.pull-right ul li a' ) as $i => $a ) {
					$fileName = '';
					switch ( $i ) {
						case 0:
							$fileName = sprintf( '%03d', $chaptersCounter ) . "-{$name}-code.zip";
							break;
						case 1:
							$fileName = sprintf( '%03d', $chaptersCounter ) . "-{$name}.mp4";
							break;
						case 2:
							$fileName = sprintf( '%03d', $chaptersCounter ) . "-{$name}-script.zip";
							break;
					}
					
					if ( ! $fileName ) {
						$this->io->warning( 'Unable to get download links' );
					}
					
					if ( file_exists( "{$coursePath}/{$fileName}" ) ) {
						$this->io->writeln( "File '{$fileName}' was already downloaded" );
						
						continue;
					}
					
					$this->downloadFile( $a->getAttribute( 'href' ), $coursePath, $fileName );
				}
				
				$this->io->newLine();
			}
		}
		
		$this->io->success( 'Finished' );
	}
	
	/**
	 * Login
	 */
	private function login(): void {
		
		if ( $this->alreadyLoggedIn() ) {
			return;
		}
		
		$this->io->note( 'Du muss dich einlogen...' );
		
		$response = $this->client->get( 'login' );
		
		$csrfToken = '';
		$crawler   = new Crawler( $response->getBody()->getContents() );
		foreach ( $crawler->filter( 'input' ) as $input ) {
			if ( $input->getAttribute( 'name' ) === '_csrf_token' ) {
				$csrfToken = $input->getAttribute( 'value' );
			}
		}
		
		if ( empty( $csrfToken ) ) {
			throw new \RuntimeException( 'Unable to authenticate' );
		}
		
		$currentUrl = NULL;
		
		$usersEmail    = $this->configs['EMAIL'];
		$usersPassword = $this->configs['PASSWORD'];
		
		if ( empty( $usersPassword ) ) {
			// PWD Promt?
			$usersPassword = $this->io->askHidden( 'Enter you password...' );
		}
		
		
		$this->client->post( 'login_check', [
			'form_params' => [
				'_email'      => $usersEmail,
				'_password'   => $usersPassword,
				'_csrf_token' => $csrfToken
			],
			'on_stats'    => static function ( TransferStats $stats ) use ( &$currentUrl ) {
				$currentUrl = $stats->getEffectiveUri();
			},
			'cookies'     => $this->cookieFile
		] );
		
		if ( (string) $currentUrl !== 'https://symfonycasts.com/' ) {
			throw new \RuntimeException( 'Authorization failed.' );
		}
	}
	
	/**
	 * Checks authorized via cookie-file and login() needed
	 *
	 * @return bool
	 */
	private function alreadyLoggedIn(): bool {
		
		$cookieFilePath = '..' . DIRECTORY_SEPARATOR . $this->configs['COOKIE_FILE_NAME'];
		if ( ! $this->filesystem->exists( $cookieFilePath ) ) {
			return FALSE;
		}
		
		$this->cookieFile = new FileCookieJar( $cookieFilePath, TRUE );
		$currentUrl       = NULL;
		
		$this->client->get( 'login', [
			'cookies'  => $this->cookieFile,
			'on_stats' => static function ( TransferStats $stats ) use ( &$currentUrl ) {
				$currentUrl = $stats->getEffectiveUri();
			},
		] );
		
		if ( $currentUrl && (string) $currentUrl === 'https://symfonycasts.com/' ) {
			$this->io->note( 'Auth via cookie-file' );
			
			return TRUE;
		}
		
		return FALSE;
	}
	
	/**
	 * Fetch courses
	 *
	 * @return array
	 */
	private function fetchCourses(): array {
		$this->io->title( 'Fetching courses...' );
		
		$blueprintFile = __DIR__ . '/../blueprint.json';
		if ( file_exists( $blueprintFile ) ) {
			return json_decode( file_get_contents( $blueprintFile ), TRUE );
		}
		
		$response = $this->client->get( '/courses/filtering' );
		
		$courses  = [];
		$crawler  = new Crawler( $response->getBody()->getContents() );
		$elements = $crawler->filter( '.js-course-item > a' );
		
		$progressBar = $this->io->createProgressBar( $elements->count() );
		$progressBar->setFormat( '<info>[%bar%]</info> %message%' );
		$progressBar->start();
		
		foreach ( $elements as $itemElement ) {
			
			$titleElement = new Crawler( $itemElement );
			dump($titleElement);
			$courseTitle  = $titleElement->filter( '.course-list-item-title' )->text();
			$courseUri    = $itemElement->getAttribute( 'href' );
			
			$progressBar->setMessage( $courseTitle );
			$progressBar->advance();
			
			$chapters = [];
			$response = $this->client->get( $courseUri );
			$crawler  = new Crawler( $response->getBody()->getContents() );
			foreach ( $crawler->filter( 'div[class*=play-circle-popup] > a' ) as $a ) {
				if ( $a->getAttribute( 'href' ) === '#' ) {
					continue;
				}
				
				$url      = explode( '#', $a->getAttribute( 'href' ) )[0];
				$urlParts = explode( '/', $url );
				
				$chapters[ end( $urlParts ) ] = $url;
			}
			
			$courses[ $courseTitle ] = $chapters;
		}
		
		$progressBar->finish();
		
		if ( ! file_put_contents( $blueprintFile, json_encode( $courses, JSON_PRETTY_PRINT ) ) ) {
			$this->io->warning( 'Unable to save course blueprint' );
		}
		
		return $courses;
	}
	
	/**
	 * Convert dash to title
	 *
	 * @param string $text
	 * @param bool $capitalizeFirstCharacter
	 *
	 * @return mixed|string
	 */
	private function dashesToTitle( $text, $capitalizeFirstCharacter = TRUE ) {
		$str = str_replace( '-', ' ', ucwords( $text, '-' ) );
		
		if ( ! $capitalizeFirstCharacter ) {
			$str = lcfirst( $str );
		}
		
		return $str;
	}
	
	/**
	 * @param string $url
	 * @param string $filePath
	 * @param string $fileName
	 *
	 * @return void
	 */
	private function downloadFile( $url, $filePath, $fileName ): void {
		$io          = $this->io;
		$progressBar = NULL;
		$file        = "{$filePath}/{$fileName}";
		
		try {
			$this->client->get( $url, [
				'save_to'         => $file,
				'allow_redirects' => [ 'max' => 2 ],
				'auth'            => [ 'username', 'password' ],
				'progress'        => function ( $total, $downloaded ) use ( $io, $fileName, &$progressBar ) {
					if ( $total && $progressBar === NULL ) {
						$progressBar = $io->createProgressBar( $total );
						$progressBar->setFormat( "<info>[%bar%]</info> {$fileName}" );
						$progressBar->start();
					}
					
					if ( $progressBar !== NULL ) {
						if ( $total === $downloaded ) {
							$progressBar->finish();
							
							return;
						}
						
						$progressBar->setProgress( $downloaded );
					}
				}
			] );
		} catch ( \Exception $e ) {
			$this->io->warning( $e->getMessage() );
			
			unlink( $file );
		}
	}
}
