<?php

namespace App\Command;

use App\Service\DownloaderService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class DownloaderCommand extends Command {
	protected static $defaultName = 'app:download';
	
	
	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int|void|null
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$io         = new SymfonyStyle( $input, $output );
		$fileSystem = new Filesystem();
		
		if ( ! file_exists( __DIR__ . '/../local.ini' ) ) {
			$io->error( "Hint: Copy run 'cp application.init local.ini' and provide required credentials" );
			
			return;
		}
		
		if ( ! $configs = parse_ini_file( __DIR__ . '/../local.ini' ) ) {
			$io->error( 'Hint: try to wrap values inside local.ini with either double or single quotes' );
			
			return;
		}
		
		$downloader = new DownloaderService( $io, $fileSystem, $configs );
		$downloader->download();
		
	}
}
