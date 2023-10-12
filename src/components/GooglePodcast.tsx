import React from 'react';

interface GooglePodcastProps {
  feed: string;
  episodeId: string;
}

const GooglePodcast: React.FC<GooglePodcastProps> = ({ feed, episodeId }) => {
  return (
    <iframe
      title="Google Podcasts"
      src={`https://www.google.com/podcasts?feed=${feed}=${episodeId}`}
      width="100%"
      height="200"
      frameBorder="0"
      allowFullScreen
    />
  );
};

export default GooglePodcast;
