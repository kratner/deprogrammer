import React from "react";
import ACastAudio from "./ACastAudio";
import YouTubeVideo from "./YouTubeVideo";
import {
  CustomAccordion,
  CustomAccordionDetails,
  CustomAccordionSummary,
  CustomExpandMoreIcon,
} from "./CustomAccordion";
interface Media {
  mediaType: string;
  contentID: string;
  description: string;
  publishedDate: string;
}

interface MediaRendererProps {
  mediaArray: Media[];
  title: string;
}

const MediaRenderer: React.FC<MediaRendererProps> = ({ mediaArray, title }) => {
  return (
    <div className="media-list">
      <CustomAccordion>
        <CustomAccordionSummary expandIcon={<CustomExpandMoreIcon />}>
          <h2>{title}</h2>
        </CustomAccordionSummary>
        <CustomAccordionDetails>
          <ul>
            {mediaArray.map((media, index) => (
              <li key={index}>
                <CustomAccordion>
                  <CustomAccordionSummary expandIcon={<CustomExpandMoreIcon />}>
                    <h3>{media.description}</h3>
                  </CustomAccordionSummary>
                  <CustomAccordionDetails>
                    {media.mediaType === "ACastAudio" ? (
                      <ACastAudio
                        contentID={media.contentID}
                        description={media.description}
                      />
                    ) : media.mediaType === "YouTubeVideo" ? (
                      <YouTubeVideo
                        contentID={media.contentID}
                        description={media.description}
                      />
                    ) : // Handle other media types as needed
                    null}
                  </CustomAccordionDetails>
                </CustomAccordion>
              </li>
            ))}
          </ul>
        </CustomAccordionDetails>
      </CustomAccordion>
    </div>
  );
};

export default MediaRenderer;
